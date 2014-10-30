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

class main extends AWS_CONTROLLER
{
	public function get_access_rule()
	{
		$rule_action['rule_type'] = 'white';
		
		if ($this->user_info['permission']['visit_question'] AND $this->user_info['permission']['visit_site'])
		{
			$rule_action['actions'][] = 'square';
			$rule_action['actions'][] = 'index';
		}
		
		return $rule_action;
	}
	
	public function index_action()
	{
		// 如果没有指定id，跳转到“发现”页
		if (! isset($_GET['id']))
		{
			HTTP::redirect('/diary/square/');
		}
		
		// 如果指定了通知id，读取该用户的通知
		if ($_GET['notification_id'])
		{
			$this->model('notify')->read_notification($_GET['notification_id'], $this->user_id);
		}
		
		// 如果是移动端访问，并且不忽略ua判断，则跳转到移动页
		if (is_mobile() AND HTTP::get_cookie('_ignore_ua_check') != 'TRUE')
		{
			HTTP::redirect('/m/diary/' . $_GET['id']);
		}
		
		// 读取指定id的日记，如果出错给出提示
		if (! $diary_info = $this->model('diary')->get_diary_info_by_id($_GET['id']))
		{
			H::redirect_msg(AWS_APP::lang()->_t('日记不存在或已被删除'), '/home/explore/');
		}

		// 如果日记带有附件
		if ($diary_info['has_attach'])
		{
			// 从attach表中读出所有类型为diary的指定item_id的缩略图
			$diary_info['attachs'] = $this->model('publish')->get_attach('diary', $diary_info['id'], 'min');
			// 正则匹配出日记正文中所有附件id
			$diary_info['attachs_ids'] = FORMAT::parse_attachs($diary_info['message'], true);
		}
		
		// 获取日记发布者用户信息
		$diary_info['user_info'] = $this->model('account')->get_user_info_by_uid($diary_info['uid'], true);
		//  
		$diary_info['message'] = FORMAT::parse_attachs(nl2br(FORMAT::parse_markdown($diary_info['message'])));
		
		// 如果当前为登录用户
		if ($this->user_id)
		{
			// 获取日记的投票信息
			$diary_info['vote_info'] = $this->model('diary')->get_diary_vote_by_id('diary', $diary_info['id'], null, $this->user_id);
		}
		
		// 获取日记参与投票的用户
		$diary_info['vote_users'] = $this->model('diary')->get_diary_vote_users_by_id('diary', $diary_info['id'], 1, 10);
		
		/*
		// 读取日记中的医生名称
		if ($diary_info['doctor_id']){
		    if ($diary_doctor_info = $this->model('diary')->get_doctor_info_by_id($diary_info['doctor_id']))
    		{
    		    $diary_info['doctor_info'] = $diary_doctor_info;
    		}
		}

		// 读取日记中的医院名称
		if ($diary_info['hospital_id']){
		    if ($diary_hospital_info = $this->model('diary')->get_hospital_info_by_id($diary_info['hospital_id']))
    		{
    		    $diary_info['hospital_info'] = $diary_hospital_info;
    		}
		}
		*/

		// 为模板的diary_info变量赋值
		TPL::assign('diary_info', $diary_info);
		// 读取该日记id对应话题，为模板的diary_topics变量赋值
		TPL::assign('diary_topics', $this->model('topic')->get_topics_by_item_id($diary_info['id'], 'diary'));
		// 读取日记发布者的威望值，为模板的reputation_topics变量赋值
		TPL::assign('reputation_topics', $this->model('people')->get_user_reputation_topic($diary_info['user_info']['uid'], $user['reputation'], 5));
		// 定义标题面包屑
		$this->crumb($diary_info['title'], '/diary/' . $diary_info['id']);
		// 检测当前操作是否需要验证码
		TPL::assign('human_valid', human_valid('answer_valid_hour'));
		
		if ($_GET['item_id'])
		{
			// 读取指定item_id下的评论
			$comments[] = $this->model('diary')->get_comment_by_id($_GET['item_id']);
		}
		else
		{
			// 读取指定日记id下的评论
			$comments = $this->model('diary')->get_comments($diary_info['id'], $_GET['page'], 100);
		}
		
		// 如果有评论而且当前为登录用户
		if ($comments AND $this->user_id)
		{
			// 读取每条评论中当前用户的投票信息
			foreach ($comments AS $key => $val)
			{
				$comments[$key]['vote_info'] = $this->model('diary')->get_diary_vote_by_id('comment', $val['id'], 1, $this->user_id);
			}
		}
		
		//如果是登录用户
		if ($this->user_id)
		{
			// 读取当前用户和日记发布者的好友关系，为模板的user_follow_check变量赋值
			TPL::assign('user_follow_check', $this->model('follow')->user_follow_check($this->user_id, $diary_info['uid']));
		}
		// 读取日记标题相关的问题列表，为模板的question_related_list变量赋值
		TPL::assign('question_related_list', $this->model('question')->get_related_question_list(null, $diary_info['title']));
		// 更新日记阅读数
		$this->model('diary')->update_views($diary_info['id']);
		// 为模板的comments变量赋值，评论信息
		TPL::assign('comments', $comments);
		// 为模板的comments_count变量赋值，日记的评论数
		TPL::assign('comments_count', $diary_info['comments']);
		// 为模板的human_valid变量赋值，是否需要验证码
		TPL::assign('human_valid', human_valid('answer_valid_hour'));
		// 为模板的pagination变量赋值，评论分页信息
		TPL::assign('pagination', AWS_APP::pagination()->initialize(array(
			'base_url' => get_js_url('/diary/id-' . $diary_info['id']), 
			'total_rows' => $diary_info['comments'],
			'per_page' => 100
		))->create_links());

		// 模板设置meta标签中的keywords
		TPL::set_meta('keywords', implode(',', $this->model('system')->analysis_keyword($diary_info['title'])));
		// 模板设置meta标签中的description
		TPL::set_meta('description', $diary_info['title'] . ' - ' . cjk_substr(str_replace("\r\n", ' ', strip_tags($diary_info['message'])), 0, 128, 'UTF-8', '...'));
		// 为模板的attach_access_key变量赋值，当前用户ID和时间戳的md5
		TPL::assign('attach_access_key', md5($this->user_id . time()));
		//输出模板diary/index
		TPL::output('diary/index');
	}

	public function square_action()
	{
		// 若是移动设备，则转向日记广场的移动模板
		if (is_mobile() AND HTTP::get_cookie('_ignore_ua_check') != 'TRUE')
		{
			HTTP::redirect('/m/diary_square/' . $_GET['id']);
		}

		$this->crumb(AWS_APP::lang()->_t('日记'), '/diary/square/');
		// 若指定了分类
		if ($_GET['category'])
		{
			if (is_numeric($_GET['category']))
			{
				// 若分类为数字，读取指定分类信息
				$category_info = $this->model('system')->get_category_info($_GET['category']);
			}
			else
			{
				// 否则按url token读取分类信息
				$category_info = $this->model('system')->get_category_info_by_url_token($_GET['category']);
			}
		}
		
		// 若指定了feature_id
		if ($_GET['feature_id'])
		{
			// 按话题id读取日记列表
			$diary_list = $this->model('diary')->get_diarys_list_by_topic_ids($_GET['page'], get_setting('contents_per_page'), 'add_time DESC', $this->model('feature')->get_topics_by_feature_id($_GET['feature_id']));
			// 用上述函数中得到的统计值为变量赋值
			$diary_list_total = $this->model('diary')->diary_list_total;
			// 若能读取指定feature_id的内容
			if ($feature_info = $this->model('feature')->get_feature_by_id($_GET['feature_id']))
			{
				// 设置当前面包屑
				$this->crumb($feature_info['title'], '/diary/square/feature_info-' . $category_info['id']);
			
				TPL::assign('feature_info', $feature_info);
			}
		}
		else
		{
			// 按分类id读取日记列表
			$diary_list = $this->model('diary')->get_diarys_list($category_info['id'], $_GET['page'], get_setting('contents_per_page'), 'add_time DESC');
			// 用上述函数中得到的统计值为变量赋值
			$diary_list_total = $this->model('diary')->found_rows();
		}
		// 如果得到日记列表
		if ($diary_list)
		{
			// 遍历日记列表
			foreach ($diary_list AS $key => $val)
			{
				// 将所有日记id保存在数组中
				$diary_ids[] = $val['id'];
				// 将所有用户id保存在数组中
				$diary_uids[$val['uid']] = $val['uid'];
			}
			// 读取日记 id对应的话题信息
			$diary_topics = $this->model('topic')->get_topics_by_item_ids($diary_ids, 'diary');
			// 读取日记用户id对应的用户信息
			$diary_users_info = $this->model('account')->get_user_info_by_uids($diary_uids);
			// 将每条日记的用户信息保存在diary_list数组中
			foreach ($diary_list AS $key => $val)
			{
				$diary_list[$key]['user_info'] = $diary_users_info[$val['uid']];
			}
		}
		
		// 导航
		if (TPL::is_output('block/content_nav_menu.tpl.htm', 'diary/square'))
		{
			TPL::assign('content_nav_menu', $this->model('menu')->get_nav_menu_list('diary'));
		}

		//边栏热门话题
		if (TPL::is_output('block/sidebar_hot_topics.tpl.htm', 'diary/square'))
		{
			TPL::assign('sidebar_hot_topics', $this->model('module')->sidebar_hot_topics($category_info['id']));
		}
		
		if ($category_info)
		{
			TPL::assign('category_info', $category_info);
			
			$this->crumb($category_info['title'], '/diary/square/category-' . $category_info['id']);
			
			$meta_description = $category_info['title'];
			
			if ($category_info['description'])
			{
				$meta_description .= ' - ' . $category_info['description'];
			}
			
			TPL::set_meta('description', $meta_description);
		}
		
		TPL::assign('diary_list', $diary_list);
		TPL::assign('diary_topics', $diary_topics);
		
		TPL::assign('hot_diarys', $this->model('diary')->get_diarys_list(null, 1, 10, 'votes DESC'));
		
		TPL::assign('pagination', AWS_APP::pagination()->initialize(array(
			'base_url' => get_js_url('/diary/square/category_id-' . $_GET['category_id'] . '__feature_id-' . $_GET['feature_id']), 
			'total_rows' => $diary_list_total,
			'per_page' => get_setting('contents_per_page')
		))->create_links());
		
		TPL::output('diary/square');
	}
}
