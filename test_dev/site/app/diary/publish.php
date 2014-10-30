<?php
/*
+--------------------------------------------------------------------------
|   WeCenter [#RELEASE_VERSION#]
|   ========================================
|   by WeCenter Software
|   © 2011 - 2013 WeCenter. All Rights Reserved
|   http://www.wecenter.com
|   ========================================
|   Support: WeCenter@qq.com
|   
+---------------------------------------------------------------------------
*/


if (!defined('IN_ANWSION'))
{
	die;
}

class publish extends AWS_CONTROLLER
{
	public function get_access_rule()
	{
		$rule_action['rule_type'] = 'white'; //黑名单,黑名单中的检查  'white'白名单,白名单以外的检查
		$rule_action['actions'] = array();
		return $rule_action;
	}

	public function setup()
	{
		$this->crumb(AWS_APP::lang()->_t('发布日记'), '/diary/publish/');
	}

	public function index_action()
	{
		// 若指定了id
		if ($_GET['id'])
		{
			// 若找不到指定id的日记
			if (!$diary_info = $this->model('diary')->get_diary_info_by_id($_GET['id']))
			{
				H::redirect_msg(AWS_APP::lang()->_t('指定日记不存在'));
			}
			// 若用户权限不够
			if (!$this->user_info['permission']['is_administortar'] AND !$this->user_info['permission']['is_moderator'] AND !$this->user_info['permission']['edit_diary'])
			{
				// 且不是当前日记的发布者
				if ($diary_info['uid'] != $this->user_id)
				{
					H::redirect_msg(AWS_APP::lang()->_t('你没有权限编辑这篇日记'), '/diary/' . $_GET['id']);
				}
			}

			// 为模板变量赋值
			TPL::assign('diary_info', $diary_info);
			TPL::assign('diary_topics', $this->model('topic')->get_topics_by_item_id($diary_info['id'], 'diary'));
		}
		// 若没有指定id且用户权限不够
		else if (!$this->user_info['permission']['publish_diary'])
		{
			H::redirect_msg(AWS_APP::lang()->_t('你所在用户组没有权限发布日记'));
		}
		// 若是post来的，且有内容
		else if ($this->is_post() AND $_POST['message'])
		{
			// 以post内容为模板变量赋值
			TPL::assign('diary_info', array(
				'title' => $_POST['title'],
				'message' => $_POST['message']
			));
		}
		else
		{
			// 否则读取当前用户的日记草稿
			$draft_content = $this->model('draft')->get_data(1, 'diary', $this->user_id);
			// 为模板变量赋值
			TPL::assign('diary_info', array(
				'title' => $_POST['title'],
				'message' => $draft_content['message']
			));
		}

		if (($this->user_info['permission']['is_administortar'] OR $this->user_info['permission']['is_moderator'] OR $diary_info['uid'] == $this->user_id AND $_GET['id']) OR !$_GET['id'])
		{
			TPL::assign('attach_access_key', md5($this->user_id . time()));
		}

		if (get_setting('category_enable') == 'Y')
		{
			TPL::assign('diary_category_list', $this->model('system')->build_category_html('question', 0, $diary_info['category_id']));
		}
		
		TPL::assign('human_valid', human_valid('question_valid_hour'));
		
		TPL::import_js('js/app/publish.js');
		TPL::import_js('js/editor/jquery-ui.js');
		TPL::import_css('js/editor/jquery-ui.css');
		
		if (get_setting('advanced_editor_enable') == 'Y')
		{
			// codemirror
			TPL::import_css('js/editor/codemirror/lib/codemirror.css');
			TPL::import_js('js/editor/codemirror/lib/codemirror.js');
			TPL::import_js('js/editor/codemirror/lib/util/continuelist.js');
			TPL::import_js('js/editor/codemirror/mode/xml/xml.js');
			TPL::import_js('js/editor/codemirror/mode/markdown/markdown.js');

			// editor
			TPL::import_js('js/editor/jquery.markitup.js');
			TPL::import_js('js/editor/markdown.js');
			TPL::import_js('js/editor/sets/default/set.js');
		}
		
		TPL::output('diary/publish');
	}
	
	public function wait_approval_action()
	{
		if ($_GET['question_id'])
		{
			if ($_GET['_is_mobile'])
			{
				$url = '/m/question/' . $_GET['question_id'];
			}
			else
			{
				$url = '/question/' . $_GET['question_id'];
			}
		}
		else
		{
			$url = '/';
		}
		
		H::redirect_msg(AWS_APP::lang()->_t('发布成功, 请等待管理员审核...'), $url);
	}
}