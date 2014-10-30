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

class diary_class extends AWS_MODEL
{
	public function get_diary_info_by_id($diary_id)
	{
		return $this->fetch_row('zxj_diary', 'id = ' . intval($diary_id));
	}

	public function get_doctor_info_by_id($doctor_id)
	{
	    return $this->fetch_row('zxj_doctor', 'doctor_id = ' . intval($doctor_id));
	}

	public function get_hospital_info_by_id($hospital_id)
	{
	    return $this->fetch_row('zxj_hospital', 'hospital_id = ' . intval($hospital_id));
	}

	public function get_diary_info_by_ids($diary_ids)
	{
		if (!is_array($diary_ids) OR sizeof($diary_ids) == 0)
		{
			return false;
		}
		
		array_walk_recursive($diary_ids, 'intval_string');
		
	    if ($diarys_list = $this->fetch_all('zxj_diary', "id IN(" . implode(',', $diary_ids) . ")"))
	    {
		    foreach ($diarys_list AS $key => $val)
		    {
		    	$result[$val['id']] = $val;
		    }
	    }
	    
	    return $result;
	}
	
	public function get_comment_by_id($comment_id)
	{
		if ($comment = $this->fetch_row('zxj_diary_comments', 'id = ' . intval($comment_id)))
		{
			$comment_user_infos = $this->model('account')->get_user_info_by_uids(array(
				$comment['uid'],
				$comment['at_uid']
			));
			
			$comment['user_info'] = $comment_user_infos[$comment['uid']];
			$comment['at_user_info'] = $comment_user_infos[$comment['at_uid']];
		}
		
		return $comment;
	}
	
	public function get_comments($diary_id, $page, $per_page)
	{
		if ($comments = $this->fetch_page('zxj_diary_comments', 'diary_id = ' . intval($diary_id), 'add_time ASC', $page, $per_page))
		{
			foreach ($comments AS $key => $val)
			{
				$comment_uids[$val['uid']] = $val['uid'];
				
				if ($val['at_uid'])
				{
					$comment_uids[$val['at_uid']] = $val['at_uid'];
				}
			}
			
			if ($comment_uids)
			{
				$comment_user_infos = $this->model('account')->get_user_info_by_uids($comment_uids);
			}
			
			foreach ($comments AS $key => $val)
			{
				$comments[$key]['user_info'] = $comment_user_infos[$val['uid']];
				$comments[$key]['at_user_info'] = $comment_user_infos[$val['at_uid']];
			}
		}
		
		return $comments;
	}

	public function publish_diary($title, $message, $uid, $topics = null, $category_id = null, $attach_access_key = null, $create_topic = true, $surgery_date, $surgery_cost, $doctor_id, $hospital_id, $doctor_name, $hospital_name)
	{
		if ($diary_id = $this->insert('zxj_diary', array(
				'uid' => intval($uid),
				'title' => htmlspecialchars($title),
				'message' => htmlspecialchars($message),
				'category_id' => intval($category_id),
				'surgery_date' => strtotime($surgery_date),
		        'surgery_cost' => intval($surgery_cost),
		    	'doctor_id' => intval($doctor_id),
		    	'doctor_name' => htmlspecialchars($doctor_name),
		    	'hospital_id' => intval($hospital_id),
		    	'hospital_name' => htmlspecialchars($hospital_name),
		        'add_time' => time()
		)))
		{
			set_human_valid('question_valid_hour');
				
			if (is_array($topics))
			{
				foreach ($topics as $key => $topic_title)
				{
					$topic_id = $this->model('topic')->save_topic($topic_title, $uid, $create_topic);
						
					$this->model('topic')->save_topic_relation($uid, $topic_id, $diary_id, 'diary');
				}
			}
				
			if ($attach_access_key)
			{
				$this->model('publish')->update_attach('diary', $diary_id, $attach_access_key);
			}
				
			$this->push_index($title, $diary_id);

			// 记录日志
			ACTION_LOG::save_action($uid, $diary_id, ACTION_LOG::CATEGORY_DIARY, ACTION_LOG::ADD_DIARY, htmlspecialchars($title), htmlspecialchars($message), 0);
				
			$this->model('posts')->set_posts_index($diary_id, 'diary');
		}
	
		return $diary_id;
	}

	public function publish_diary_comment($diary_id, $message, $uid, $at_uid = null)
	{
		// 读取指定id的日记数据
		if (!$diary_info = $this->get_diary_info_by_id($diary_id))
		{
			return false;
		}
		// 插入一条评论数据，返回其id
		$comment_id = $this->insert('zxj_diary_comments', array(
				'uid' => intval($uid),
				'diary_id' => intval($diary_id),
				'message' => htmlspecialchars($message),
				'add_time' => time(),
				'at_uid' => intval($at_uid)
		));
		// 统计评论条数，更新日记表中的评论数
		$this->update('zxj_diary', array(
				'comments' => $this->count('zxj_diary_comments', 'diary_id = ' . intval($diary_id))
		), 'id = ' . intval($diary_id));
		// 如果被回复的用户不是发表评论的用户
		if ($at_uid AND $at_uid != $uid)
		{
			// 向被回复的用户发送通知
			$this->model('notify')->send($uid, $at_uid, notify_class::TYPE_DIARY_COMMENT_AT_ME, notify_class::CATEGORY_DIARY, $diary_info['id'], array(
					'from_uid' => $uid,
					'diary_id' => $diary_info['id'],
					'item_id' => $comment_id
			));
		}
	
		set_human_valid('answer_valid_hour');
		// 如果评论者不是日记发布者
		if ($diary_info['uid'] != $uid)
		{
			// 向被评论的日记发布者发送通知
			$this->model('notify')->send($uid, $diary_info['uid'], notify_class::TYPE_DIARY_NEW_COMMENT, notify_class::CATEGORY_DIARY, $diary_info['id'], array(
					'from_uid' => $uid,
					'diary_id' => $diary_info['id'],
					'item_id' => $comment_id
			));
		}
	
		if ($weixin_user = $this->model('openid_weixin')->get_user_info_by_uid($diary_info['uid']) AND $diary_info['uid'] != $uid)
		{
			$weixin_user_info = $this->model('account')->get_user_info_by_uid($weixin_user['uid']);
	
			if ($weixin_user_info['weixin_settings']['NEW_DIARY_COMMENT'] != 'N')
			{
				$this->model('weixin')->send_text_message($weixin_user['openid'], "您的日记 [" . $diary_info['title'] . "] 收到了新的评论:\n\n" . strip_tags($message), $this->model('openid_weixin')->redirect_url('/diary/' . $diary_info['id']));
			}
		}
	
		$this->model('posts')->set_posts_index($diary_info['id'], 'diary');
	
		return $comment_id;
	}
	
	public function remove_diary($diary_id)
	{
		if (!$diary_info = $this->get_diary_info_by_id($diary_id))
		{
			return false;
		}
		
		$this->delete('zxj_diary_comments', "diary_id = " . intval($diary_id)); // 删除关联的回复内容

		$this->delete('topic_relation', "`type` = 'diary' AND item_id = " . intval($diary_id));		// 删除话题关联
				
		ACTION_LOG::delete_action_history('associate_type = ' . ACTION_LOG::CATEGORY_DIARY . ' AND associate_action IN(' . ACTION_LOG::ADD_DIARY . ', ' . ACTION_LOG::ADD_AGREE_DIARY . ') AND associate_id = ' . intval($diary_id));	// 删除动作
		
		// 删除附件
		if ($attachs = $this->model('publish')->get_attach('zxj_diary', $diary_id))
		{
			foreach ($attachs as $key => $val)
			{
				$this->model('publish')->remove_attach($val['id'], $val['access_key']);
			}
		}
		
		$this->model('notify')->delete_notify('model_type = 8 AND source_id = ' . intval($diary_id));	// 删除相关的通知
		
		$this->model('posts')->remove_posts_index($diary_id, 'diary');
		
		return $this->delete('zxj_diary', 'id = ' . intval($diary_id));
	}
	
	public function remove_comment($comment_id)
	{
		if ($comment_info = $this->get_comment_by_id($comment_id))
		{
			$this->delete('zxj_diary_comments', 'id = ' . intval($comment_id));
			
			$this->update('zxj_diary', array(
				'comments' => $this->count('zxj_diary_comments', 'diary_id = ' . $comment_info['diary_id'])
			), 'id = ' . $comment_info['diary_id']);
			
			return true;
		}
	}
	
	public function update_diary($diary_id, $title, $message, $topics, $category_id, $create_topic, $surgery_date, $surgery_cost, $doctor_id, $hospital_id, $doctor_name, $hospital_name)
	{
		if (!$diary_info = $this->model('diary')->get_diary_info_by_id($diary_id))
		{
			return false;
		}
		
		$this->delete('topic_relation', 'item_id = ' . intval($diary_id) . " AND `type` = 'diary'");
		
		if (is_array($topics))
		{
			foreach ($topics as $key => $topic_title)
			{
				$topic_id = $this->model('topic')->save_topic($topic_title, $uid, $create_topic);
				
				$this->model('topic')->save_topic_relation($this->user_id, $topic_id, $diary_id, 'diary');
			}
		}
		
		$this->model('search_fulltext')->push_index('diary', htmlspecialchars($title), $diary_info['id']);
		
		$this->update('zxj_diary', array(
			'title' => htmlspecialchars($title),
			'message' => htmlspecialchars($message),
			'surgery_date' => strtotime($surgery_date),
	        'surgery_cost' => intval($surgery_cost),
	    	'doctor_id' => intval($doctor_id),
	    	'doctor_name' => htmlspecialchars($doctor_name),
	    	'hospital_id' => intval($hospital_id),
		    'hospital_name' => htmlspecialchars($hospital_name),
		    'category_id' => intval($category_id)
		), 'id = ' . intval($diary_id));
		
		$this->model('posts')->set_posts_index($diary_id, 'diary');
		
		return true;
	}
	
	public function get_diarys_list($category_id, $page, $per_page, $order_by)
	{
		if ($category_id)
		{
			$where = 'category_id = ' . intval($category_id);
		}
		
		return $this->fetch_page('zxj_diary', $where, $order_by, $page, $per_page);
	}
	
	public function get_diarys_list_by_topic_ids($page, $per_page, $order_by, $topic_ids)
	{
		if (!$topic_ids)
		{
			return false;
		}

		if (!is_array($topic_ids))
		{
			$topic_ids = array(
				$topic_ids
			);
		}

		array_walk_recursive($topic_ids, 'intval_string');

		$result_cache_key = 'diary_list_by_topic_ids_' . md5(implode('_', $topic_ids) . $order_by . $page . $per_page);

		$found_rows_cache_key = 'diary_list_by_topic_ids_found_rows_' . md5(implode('_', $topic_ids) . $order_by . $page . $per_page);
		
		if (!$result = AWS_APP::cache()->get($result_cache_key) OR $found_rows = AWS_APP::cache()->get($found_rows_cache_key))
		{
			$topic_relation_where[] = '`topic_id` IN(' . implode(',', $topic_ids) . ')';
			$topic_relation_where[] = "`type` = 'diary'";
		
			if ($topic_relation_query = $this->query_all("SELECT item_id FROM " . get_table('topic_relation') . " WHERE " . implode(' AND ', $topic_relation_where)))
			{
				foreach ($topic_relation_query AS $key => $val)
				{
					$diary_ids[$val['item_id']] = $val['item_id'];
				}
			}
			
			if (!$diary_ids)
			{
				return false;
			}
			
			$where[] = "id IN (" . implode(',', $diary_ids) . ")";
		}


		if (!$result)
		{
			$result = $this->fetch_page('zxj_diary', implode(' AND ', $where), $order_by, $page, $per_page);

			AWS_APP::cache()->set($result_cache_key, $result, get_setting('cache_level_high'));
		}
		
		
		if (!$found_rows)
		{
			$found_rows = $this->found_rows();

			AWS_APP::cache()->set($found_rows_cache_key, $found_rows, get_setting('cache_level_high'));
		}

		$this->diary_list_total = $found_rows;
		
		return $result;
	}
	
	public function lock_diary($diary_id, $lock_status = true)
	{
		return $this->update('zxj_diary', array(
			'lock' => intval($lock_status)
		), 'id = ' . intval($diary_id));
	}
	
	public function diary_vote($type, $item_id, $rating, $uid, $reputation_factor, $item_uid)
	{
		$this->delete('zxj_diary_vote', "`type` = '" . $this->quote($type) . "' AND item_id = " . intval($item_id) . ' AND uid = ' . intval($uid));
		
		if ($rating)
		{
			if ($diary_vote = $this->fetch_row('zxj_diary_vote', "`type` = '" . $this->quote($type) . "' AND item_id = " . intval($item_id) . " AND rating = " . intval($rating) . ' AND uid = ' . intval($uid)))
			{			
				$this->update('zxj_diary_vote', array(
					'rating' => intval($rating),
					'time' => time(),
					'reputation_factor' => $reputation_factor
				), 'id = ' . intval($diary_vote['id']));
			}
			else
			{
				$this->insert('zxj_diary_vote', array(
					'type' => $type,
					'item_id' => intval($item_id),
					'rating' => intval($rating),
					'time' => time(),
					'uid' => intval($uid),
					'item_uid' => intval($item_uid),
					'reputation_factor' => $reputation_factor
				));
			}
		}
		
		switch ($type)
		{
			case 'diary':
				$this->update('zxj_diary', array(
					'votes' => $this->count('zxj_diary_vote', "`type` = '" . $this->quote($type) . "' AND item_id = " . intval($item_id) . " AND rating = 1")
				), 'id = ' . intval($item_id));
				
				switch ($rating)
				{
					case 1:
						ACTION_LOG::save_action($uid, $item_id, ACTION_LOG::CATEGORY_DIARY, ACTION_LOG::ADD_AGREE_DIARY);
					break;
					
					case -1:
						ACTION_LOG::delete_action_history('associate_type = ' . ACTION_LOG::CATEGORY_DIARY . ' AND associate_action = ' . ACTION_LOG::ADD_AGREE_DIARY . ' AND uid = ' . intval($uid) . ' AND associate_id = ' . intval($item_id));
					break;
				}
			break;
			
			case 'comment':
				$this->update('zxj_diary_comments', array(
					'votes' => $this->count('zxj_diary_vote', "`type` = '" . $this->quote($type) . "' AND item_id = " . intval($item_id) . " AND rating = 1")
				), 'id = ' . intval($item_id));
			break;
		}
		
		$this->model('account')->sum_user_agree_count($item_uid);
		
		return true;
	}
	
	public function get_diary_vote_by_id($type, $item_id, $rating = null, $uid = null)
	{
		if ($diary_vote = $this->get_diary_vote_by_ids($type, array(
			$item_id
		), $rating, $uid))
		{
			return end($diary_vote[$item_id]);
		}
	}
	
	public function get_diary_vote_by_ids($type, $item_ids, $rating = null, $uid = null)
	{
		if (!is_array($item_ids))
		{
			return false;
		}
		
		if (sizeof($item_ids) == 0)
		{
			return false;
		}
		
		array_walk_recursive($item_ids, 'intval_string');
		
		$where[] = "`type` = '" . $this->quote($type) . "'";
		$where[] = 'item_id IN(' . implode(',', $item_ids) . ')';
		
		if ($rating)
		{
			$where[] = 'rating = ' . intval($rating);
		}
		
		if ($uid)
		{
			$where[] = 'uid = ' . intval($uid);
		}
		
		if ($diary_votes = $this->fetch_all('zxj_diary_vote', implode(' AND ', $where)))
		{
			foreach ($diary_votes AS $key => $val)
			{
				$result[$val['item_id']][] = $val;
			}
		}
		
		return $result;
	}
	
	public function get_diary_vote_users_by_id($type, $item_id, $rating = null, $limit = null)
	{
		$where[] = "`type` = '" . $this->quote($type) . "'";
		$where[] = 'item_id = ' . intval($item_id);
		
		if ($rating)
		{
			$where[] = 'rating = ' . intval($rating);
		}
		
		if ($diary_votes = $this->fetch_all('zxj_diary_vote', implode(' AND ', $where)))
		{
			foreach ($diary_votes AS $key => $val)
			{
				$uids[$val['uid']] = $val['uid'];
			}
			
			return $this->model('account')->get_user_info_by_uids($uids);	
		}
	}
	
	public function get_diary_vote_users_by_ids($type, $item_ids, $rating = null, $limit = null)
	{
		if (! is_array($item_ids))
		{
			return false;
		}
		
		if (sizeof($item_ids) == 0)
		{
			return false;
		}
		
		array_walk_recursive($item_ids, 'intval_string');
		
		$where[] = "`type` = '" . $this->quote($type) . "'";
		$where[] = 'item_id IN(' . implode(',', $item_ids) . ')';
		
		if ($rating)
		{
			$where[] = 'rating = ' . intval($rating);
		}
		
		if ($diary_votes = $this->fetch_all('diary_vote', implode(' AND ', $where)))
		{
			foreach ($diary_votes AS $key => $val)
			{
				$uids[$val['uid']] = $val['uid'];
			}
			
			$users_info = $this->model('account')->get_user_info_by_uids($uids);
			
			foreach ($diary_votes AS $key => $val)
			{
				$vote_users[$val['item_id']][$val['uid']] = $users_info[$val['uid']];
			}
			
			return $vote_users;
		}
	}
	
	public function update_views($diary_id)
	{
		if (AWS_APP::cache()->get('update_views_diary_' . md5(session_id()) . '_' . intval($diary_id)))
		{
			return false;
		}
		
		AWS_APP::cache()->set('update_views_diary_' . md5(session_id()) . '_' . intval($diary_id), time(), 60);
		
		$this->shutdown_query("UPDATE " . $this->get_table('zxj_diary') . " SET views = views + 1 WHERE id = " . intval($diary_id));
		
		return true;
	}
	
	public function set_recommend($diary_id)
	{
		$this->update('zxj_diary', array(
			'is_recommend' => 1
		), 'id = ' . intval($diary_id));
		
		$this->model('posts')->set_posts_index($diary_id, 'diary');
	}
	
	public function unset_recommend($diary_id)
	{
		$this->update('zxj_diary', array(
			'is_recommend' => 0
		), 'id = ' . intval($diary_id));
		
		$this->model('posts')->set_posts_index($diary_id, 'diary');
	}

	public function push_index($string, $item_id)
	{
		if (!$keywords = $this->model('system')->analysis_keyword($string))
		{
			return false;
		}
	
		$search_code = $this->model('search_fulltext')->encode_search_code($keywords);
	
		return $this->update('zxj_diary', array(
			'title_fulltext' => $search_code
		), 'id = ' . intval($item_id));
	}
}