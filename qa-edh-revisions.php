<?php
/*
	Question2Answer Edit History plugin
	License: http://www.gnu.org/licenses/gpl.html
*/

class qa_edh_revisions
{
	private $directory;
	private $urltoroot;
	private $reqmatch = '#revisions(/([0-9]+))?$#';
	private $options; // stores relevant options

	public function load_module($directory, $urltoroot)
	{
		$this->directory = $directory;
		$this->urltoroot = $urltoroot;
	}

	public function suggest_requests()
	{
		return array(
			array(
				'title' => 'Edit History',
				'request' => 'revisions',
				'nav' => null,
			),
		);
	}

	public function match_request($request)
	{
		// validates the postid so we don't need to do this later
		return preg_match($this->reqmatch, $request) > 0;
	}

	public function process_request($request)
/*
	Post edits are stored in a special way. The `qa_posts` tables contains the latest version displayed (obviously).
	The `qa_edit_history` table stores each previous revision, with the time it was updated to the later one.
	So each time applies to the next revision, with `qa_posts.created` being when the first revision from
	`qa_edit_history` was posted.
*/
	{
		require $this->directory.'class.diff-string.php';
		$qa_content = qa_content_prepare();
		preg_match($this->reqmatch, $request, $matches);

		if (isset($matches[2]))
		{
			$revertid = qa_post_text('revert');
			$deleteid = qa_post_text('delete');
			// revert a revision
			if ($revertid !== null)
				$this->revert_revision($matches[2], $revertid);
			// delete a revision
			else if ($deleteid !== null)
				$this->delete_revision($matches[2], $revertid);
			// post revisions: list all edits to this post
			else
				$this->post_revisions($qa_content, $matches[2]);
		}
		// main page: list recent revisions
		else
			$this->recent_edits($qa_content);

		return $qa_content;
	}

	// Display all recent edits
	private function recent_edits(&$qa_content)
	{
		$qa_content['title'] = qa_lang_html('edithistory/main_title');
		$qa_content['custom'] = '<p>This page will list posts that have been edited recently.</p>';
	}

	// Display all the edits made to a post ($postid already validated)
	private function post_revisions(&$qa_content, $postid)
	{
		$qa_content['title'] = qa_lang_html('edithistory/plugin_title');

		// check user is allowed to view edit history
		$error = qa_user_permit_error('edit_history_view_perms');
		if ($error === 'login')
		{
			$qa_content['error'] = qa_insert_login_links( qa_lang_html('edithistory/need_login'), qa_request() );
			return;
		}
		else if ($error !== false)
		{
			$qa_content['error'] = qa_lang_html('edithistory/no_user_perms');
			return;
		}

		// get revisions from oldest to newest
		$revisions = $this->db_get_revisions($postid);

		// return 404 if no revisions
		if (count($revisions) <= 1)
		{
			header('HTTP/1.0 404 Not Found');
			$qa_content['error'] = qa_lang_html('edithistory/no_revisions');
			return $qa_content;
		}

		// censor posts; build list of userids as we go
		require_once QA_INCLUDE_DIR.'qa-util-string.php';
		$this->options = array(
			'blockwordspreg' => qa_get_block_words_preg(),
			'fulldatedays' => qa_opt('show_full_date_days'),
		);
		$userids = array();

		foreach ($revisions as &$rev)
		{
			$rev['title'] = qa_block_words_replace( $rev['title'], $this->options['blockwordspreg'] );
			$rev['content'] = qa_block_words_replace( $rev['content'], $this->options['blockwordspreg'] );
			if (!in_array($rev['userid'], $userids))
				$userids[] = $rev['userid'];
		}

		// get user handles
		$usernames = qa_userids_to_handles($userids);

		// set diff of oldest revision to its own content
		$revisions[0]['id'] = 0;
		$revisions[0]['diff_title'] = trim($revisions[0]['title']);
		$revisions[0]['diff_content'] = $revisions[0]['content'];
		$revisions[0]['handle'] = $usernames[$revisions[0]['userid']];
		$len = count($revisions);

		// run diff algorithm against each previous revision in turn
		for ($i = 1; $i < $len; $i++)
		{
			$rc =& $revisions[$i];
			$rp =& $revisions[$i-1];

			$rc['id'] = $i;
			$rc['diff_title'] = trim( diff_string::compare(qa_html($rp['title']), qa_html($rc['title'])) );
			$rc['diff_content'] = null;
			if ($rp['content'] !== $rc['content'])
				$rc['diff_content'] = trim( diff_string::compare(qa_html($rp['content']), qa_html($rc['content'])) );

			$rc['edited'] = $rp['updated'];
			$rc['editedby'] = $rp['handle'];

			$rc['handle'] = $usernames[$rc['userid']];
		}
		$revisions[0]['edited'] = $revisions[$len-1]['updated'];
		$revisions[0]['editedby'] = $revisions[$len-1]['handle'];

		// display results
		$revisions = array_reverse($revisions);
		$this->html_output($qa_content, $revisions, $postid);
	}

	// return array containing the post at each revision, oldest first
	private function db_get_revisions($postid)
	{
		// get previous revisions from qa_edit_history
		$sql =
			'SELECT postid, userid, UNIX_TIMESTAMP(updated) AS updated, title, content, tags
			 FROM ^edit_history
			 WHERE postid=#
			 ORDER BY updated';
		$result = qa_db_query_sub($sql, $postid);
		$revisions = qa_db_read_all_assoc($result);

		// get latest version of post from qa_posts
		$sql =
			'SELECT postid, type, parentid, userid, format, UNIX_TIMESTAMP(created) AS updated, title, content, tags
			 FROM ^posts
			 WHERE postid=#';
		$result = qa_db_query_sub($sql, $postid);
		$current = qa_db_read_one_assoc($result, true);

		return array_merge($revisions, array($current));
	}

	private function html_output(&$qa_content, &$revisions, $postid)
	{
		$html = '<form action="' . qa_path_html('revisions/'.$postid) . '" method="post">';

		// create link back to post
		$currRev = $revisions[0];
		if ($currRev['type'] == 'Q')
			$posturl = qa_q_path_html($currRev['postid'], $currRev['title']);
		else if ($currRev['type'] == 'A')
			$posturl = qa_q_path_html($currRev['parentid'], $currRev['title'], false, 'A', $currRev['postid']);

		if (!empty($posturl))
			$html .= '<p><a href="' . $posturl . '">' . qa_lang_html('edithistory/back_to_post') . '</a></p>';

		$num_revs = count($revisions);
		foreach ($revisions as $i=>$rev)
		{
			$updated = implode( '', qa_when_to_html($rev['edited'], $this->options['fulldatedays']) );
			$userlink = $this->user_handle_link($rev['editedby']);
			$langkey = $i < $num_revs-1 ? 'edithistory/edited_when_by' : 'edithistory/original_post_by';

			$edited_when_by = strtr(qa_lang_html($langkey), array(
				'^1' => $updated,
				'^2' => $userlink,
			));

			$html .= '<div class="diff-block">' . "\n";
			$html .= '  <div class="diff-date">';
			if ($i > 0) {
				$html .= '<button type="submit" name="delete" value="' . $rev['id'] . '" class="diff-button qa-form-tall-button qa-form-tall-button-cancel">' .
					qa_lang('edithistory/delete') . '</button>';
				$html .= '<button type="submit" name="revert" value="' . $rev['id'] . '" class="diff-button qa-form-tall-button qa-form-tall-button-reset">' .
					qa_lang('edithistory/revert') . '</button>';
			}
			else
				$html .= '<span class="diff-button">' . qa_lang_html('edithistory/current_revision') . '</span>';

			$html .= $edited_when_by;
			$html .= '</div>' . "\n";

			if (!empty($rev['diff_title']))
				$html .= '  <h2>' . $rev['diff_title'] . '</h2>' . "\n";
			if ($rev['diff_content'])
				$html .= '  <div>' . nl2br($rev['diff_content']) . '</div>' . "\n";
			else
				$html .= '  <div class="no-diff">' . qa_lang_html('edithistory/content_unchanged') . '</div>' . "\n";
			$html .= '</div>' . "\n\n";
		}

		$html .= '</form>' . "\n\n";

		$qh =& $qa_content['head_lines'];
		// prevent search engines indexing revision pages
		$qh[] = '<meta name="robots" content="noindex,follow">';
		// styles for this page
		$qh[] = '<style>';
		$qh[] = '.diff-block { padding-bottom: 20px; margin-bottom: 20px; } ';
		$qh[] = '.diff-date { margin: 5px 0; padding: 3px 6px; line-height: 26px; background: #eee; color: #000; } ';
		$qh[] = 'ins { background-color: #d1e1ad; color: #405a04; text-decoration: none; } ';
		$qh[] = 'del { background-color: #e5bdb2; color: #a82400; text-decoration: line-through; } ';
		$qh[] = '.no-diff { color: #999; } ';
		$qh[] = '.diff-button { float: right; } ';
		$qh[] = '.diff-button.qa-form-tall-button { font-size: 11px; font-weight: normal; padding: 2px 10px 3px; } ';
		$qh[] = '</style>';

		$qa_content['title'] = qa_lang_html_sub('edithistory/revision_title', $postid);
		$qa_content['custom'] = $html;
	}

	private function revert_revision($postid, $revid)
	{
		require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		$revisions = $this->db_get_revisions($postid);

		qa_post_set_content($postid, $revisions[$revid]['title'], $revisions[$revid]['content']);
		qa_redirect('revisions/'.$postid);
	}

	private function delete_revision($postid, $revid)
	{
		// require_once QA_INCLUDE_DIR.'qa-app-posts.php';
		// $revisions = $this->db_get_revisions($postid);
		// qa_post_set_content($postid, $revisions[$revid]['title'], $revisions[$revid]['content']);
		// qa_redirect('revisions/'.$postid);
	}

	private function user_handle_link($handle)
	{
		return empty($handle)
			? qa_lang_html('main/anonymous')
			: '<a href="' . qa_path_html('user/'.$handle) . '">' . qa_html($handle) . '</a>';
	}

}
