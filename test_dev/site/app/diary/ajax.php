<?php
/*
+--------------------------------------------------------------------------
|   WeCenter [#RELEASE_VERSION#]
|   ========================================
|   by Tatfook Network Team
|   © 2011 - 2013 WeCenter. All Rights Reserved
|   http://www.wecenter.com
|   ========================================
|   Support: WeCenter@qq.com
|   
+---------------------------------------------------------------------------
*/

define('IN_AJAX', TRUE);

if (!defined('IN_ANWSION'))
{
	die;
}

class ajax extends AWS_CONTROLLER
{
	public function get_access_rule()
	{
		$rule_action['rule_type'] = 'white';
		
		$rule_action['actions'] = array(
			'list'
		);
				
		return $rule_action;
	}

	public function setup()
	{
		HTTP::no_cache_header();
	}

	public function save_comment_action()
	{
		if (!$diary_info = $this->model('diary')->get_diary_info_by_id($_POST['diary_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('指定日记不存在')));
		}
		
		if ($diary_info['lock'] AND ! ($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的日记不能回复')));
		}
		
		$message = trim($_POST['message'], "\r\n\t");
		
		if (! $message)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请输入回复内容')));
		}
		
		if (strlen($message) < get_setting('answer_length_lower'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复内容字数不得少于 %s 字节', get_setting('answer_length_lower'))));
		}
		
		if (! $this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($message))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
		
		if (human_valid('answer_valid_hour') and ! AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}
		
		// !注: 来路检测后面不能再放报错提示
		if (! valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}
		
		if ($this->publish_approval_valid())
		{
			$this->model('publish')->publish_approval('diary_comment', array(
				'diary_id' => intval($_POST['diary_id']),
				'message' => $message,
				'at_uid' => intval($_POST['at_uid'])
			), $this->user_id);
				
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/wait_approval/diary_id-' . intval($_POST['diary_id']) . '__is_mobile-' . $_POST['_is_mobile'])
			), 1, null));
		}
		else
		{
			$comment_id = $this->model('diary')->publish_diary_comment($_POST['diary_id'], $message, $this->user_id, $_POST['at_uid']);
			
			$url = get_js_url('/diary/' . intval($_POST['diary_id']) . '?item_id=' . $comment_id);
			
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => $url
		), 1, null));
		}
	}
	
	public function lock_action()
	{
		if (! $this->user_info['permission']['is_moderator'] && ! $this->user_info['permission']['is_administortar'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你没有权限进行此操作')));
		}
		
		if (! $diary_info = $this->model('diary')->get_diary_info_by_id($_POST['diary_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('日记不存在')));
		}
		
		$this->model('diary')->lock_diary($_POST['diary_id'], !$diary_info['lock']);
		
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}
	
	public function remove_diary_action()
	{
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{				
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('对不起, 你没有删除日记的权限')));
		}
		
		if ($diary_info = $this->model('diary')->get_diary_info_by_id($_POST['diary_id']))
		{
			if ($this->user_id != $diary_info['uid'])
			{
				$this->model('account')->send_delete_message($diary_info['uid'], $diary_info['title'], $$diary_info['message']);
			}
					
			$this->model('diary')->remove_diary($diary_info['id']);
		}
			
		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/diary/square/')
		), 1, null));
	}
	
	public function remove_comment_action()
	{
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{				
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('对不起, 你没有删除评论的权限')));
		}

		if ($comment_info = $this->model('diary')->get_comment_by_id($_POST['comment_id']))
		{
			$this->model('diary')->remove_comment($comment_info['id']);
		}
			
		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/diary/' . $comment_info['diary_id'])
		), 1, null));
	}
	
	public function diary_vote_action()
	{
		switch ($_POST['type'])
		{
			case 'diary':
				$item_info = $this->model('diary')->get_diary_info_by_id($_POST['item_id']);
			break;
			
			case 'comment':
				$item_info = $this->model('diary')->get_comment_by_id($_POST['item_id']);
			break;
			
		}
		
		if (!$item_info)
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('内容不存在')));
		}
		
		if ($item_info['uid'] == $this->user_id)
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('不能对自己发表的内容进行投票')));
		}
		
		$reputation_factor = $this->model('account')->get_user_group_by_id($this->user_info['reputation_group'], 'reputation_factor');
		
		$this->model('diary')->diary_vote($_POST['type'], $_POST['item_id'], $_POST['rating'], $this->user_id, $reputation_factor, $item_info['uid']);
		
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}
	
	public function set_recommend_action()
	{
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{				
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('对不起, 你没有设置推荐的权限')));
		}
		
		switch ($_POST['action'])
		{
			case 'set':
				$this->model('diary')->set_recommend($_POST['diary_id']);
			break;
			
			case 'unset':
				$this->model('diary')->unset_recommend($_POST['diary_id']);
			break;
		}
			
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}

	public function fetch_question_category_action()
	{
		if (get_setting('category_enable') == 'Y')
		{
			echo $this->model('system')->build_category_json('question', 0, $question_info['category_id']);
		}
		else
		{
			echo json_encode(array());
		}
	
		exit;
	}
	
	public function answer_attach_edit_list_action()
	{
		if (!$answer_info = $this->model('answer')->get_answer_by_id($_POST['answer_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复不存在')));
		}
	
		if ($answer_info['uid'] != $this->user_id AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个附件列表')));
		}
	
		if ($answer_attach = $this->model('publish')->get_attach('answer', $_POST['answer_id']))
		{
			foreach ($answer_attach as $attach_id => $val)
			{
				$answer_attach[$attach_id]['class_name'] = $this->model('publish')->get_file_class($val['file_name']);
				$answer_attach[$attach_id]['delete_link'] = get_js_url('/publish/ajax/remove_attach/attach_id-' . base64_encode(H::encode_hash(array(
						'attach_id' => $attach_id,
						'access_key' => $val['access_key']
				))));
	
				$answer_attach[$attach_id]['attach_id'] = $attach_id;
				$answer_attach[$attach_id]['attach_tag'] = 'attach';
			}
		}
	
		H::ajax_json_output(AWS_APP::RSM(array(
		'attachs' => $answer_attach
		), 1, null));
	}
	
	public function remove_attach_action()
	{
		if ($attach_info = H::decode_hash(base64_decode($_GET['attach_id'])))
		{
			$this->model('publish')->remove_attach($attach_info['attach_id'], $attach_info['access_key']);
		}
	
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}
	
	public function publish_diary_action()
	{
		if (!$this->user_info['permission']['publish_diary'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限发布日记')));
		}
	
		if (empty($_POST['title']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入日记标题')));
		}
	
		if (get_setting('category_enable') == 'N')
		{
			$_POST['category_id'] = 1;
		}
	
		if (!$_POST['category_id'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('请选择日记分类')));
		}
	
		if (get_setting('question_title_limit') > 0 && cjk_strlen($_POST['title']) > get_setting('question_title_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('日记标题字数不得大于 %s 字节', get_setting('question_title_limit'))));
		}
	
		if (!$this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($_POST['message']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
	
		if (human_valid('question_valid_hour') AND !AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}
	
		if ($_POST['topics'] AND get_setting('question_topics_limit') AND sizeof($_POST['topics']) > get_setting('question_topics_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('单个日记话题数量最多为 %s 个, 请调整话题数量', get_setting('question_topics_limit'))));
		}
	
		if (get_setting('new_question_force_add_topic') == 'Y' AND !$_POST['topics'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请为日记添加话题')));
		}
	
		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}
	
		$this->model('draft')->delete_draft(1, 'diary', $this->user_id);
	
		if ($this->publish_approval_valid())
		{
			$this->model('publish')->publish_approval('diary', array(
					'title' => $_POST['title'],
					'message' => $_POST['message'],
					'category_id' => $_POST['category_id'],
					'topics' => $_POST['topics'],
			        'permission_create_topic' => $this->user_info['permission']['create_topic']
			), $this->user_id, $_POST['attach_access_key']);
	
			H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/publish/wait_approval/')
			), 1, null));
		}
		else
		{
			$diary_id = $this->model('diary')->publish_diary($_POST['title'], $_POST['message'], $this->user_id, $_POST['topics'], $_POST['category_id'], $_POST['attach_access_key'], $this->user_info['permission']['create_topic'], $_POST['surgery_date'], $_POST['surgery_cost'], $_POST['doctor_id'], $_POST['hospital_id'], $_POST['doctor_name'], $_POST['hospital_name']);
	
			if ($_POST['_is_mobile'])
			{
				$url = get_js_url('/m/diary/' . $diary_id);
			}
			else
			{
				$url = get_js_url('/diary/' . $diary_id);
			}
	
			H::ajax_json_output(AWS_APP::RSM(array(
			'url' => $url
			), 1, null));
		}
	}
	
	function modify_diary_action()
	{
		if (!$diary_info = $this->model('diary')->get_diary_info_by_id($_POST['diary_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('日记不存在')));
		}
	
		if ($diary_info['lock'] && !($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('日记已锁定, 不能编辑')));
		}
	
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'] AND !$this->user_info['permission']['edit_diary'])
		{
			if ($diary_info['uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这篇日记')));
			}
		}
	
		if (empty($_POST['title']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入日记标题')));
		}
	
		if (get_setting('category_enable') == 'N')
		{
			$_POST['category_id'] = 1;
		}
	
		if (!$_POST['category_id'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('请选择日记分类')));
		}
	
		if (get_setting('question_title_limit') > 0 && cjk_strlen($_POST['title']) > get_setting('question_title_limit'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('日记标题字数不得大于') . ' ' . get_setting('question_title_limit') . ' ' . AWS_APP::lang()->_t('字节')));
		}
	
		if (!$this->user_info['permission']['publish_url'] && FORMAT::outside_url_exists($_POST['message']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你所在的用户组不允许发布站外链接')));
		}
	
		if (human_valid('question_valid_hour') AND !AWS_APP::captcha()->is_validate($_POST['seccode_verify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请填写正确的验证码')));
		}
	
		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}
	
		$this->model('draft')->delete_draft(1, 'diary', $this->user_id);
	
		if ($_POST['do_delete'] AND !$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('对不起, 你没有删除日记的权限')));
		}
	
		if ($_POST['do_delete'])
		{
			if ($this->user_id != $diary_info['uid'])
			{
				$this->model('account')->send_delete_message($diary_info['uid'], $diary_info['title'], $diary_info['message']);
			}
	
			$this->model('diary')->remove_diary($diary_info['id']);
				
			H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/diary/square/')
			), 1, null));
		}
	
		$this->model('diary')->update_diary($diary_info['id'], $_POST['title'], $_POST['message'], $_POST['topics'], $_POST['category_id'], $this->user_info['permission']['create_topic'], $_POST['surgery_date'], $_POST['surgery_cost'], $_POST['doctor_id'], $_POST['hospital_id'], $_POST['doctor_name'], $_POST['hospital_name']);
	
		if ($_POST['attach_access_key'])
		{
			$this->model('publish')->update_attach('diary', $diary_info['id'], $_POST['attach_access_key']);
		}
	
		H::ajax_json_output(AWS_APP::RSM(array(
		'url' => get_js_url('/diary/' . $diary_info['id'])
		), 1, null));
	}
	
	public function save_related_link_action()
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['item_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}
	
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			if ($question_info['published_uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限执行该操作')));
			}
		}
	
		if (substr($_POST['link'], 0, 7) != 'http://' AND substr($_POST['link'], 0, 8) != 'https://')
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('链接格式不正确')));
		}
	
		$this->model('related')->add_related_link($this->user_id, 'question', $_POST['item_id'], $_POST['link']);
	
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}
	
	public function remove_related_link_action()
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['item_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}
	
		if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'])
		{
			if ($question_info['published_uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限执行该操作')));
			}
		}
	
		$this->model('related')->remove_related_link($_POST['id'], $_POST['item_id']);
	
		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}

}