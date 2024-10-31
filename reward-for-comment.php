<?php
/**
 * Plugin Name: Reward for Comment
 * Description: Reward users for creating unique content for your website
 * Author: Alexey Trofimov
 * Version: 1.0.0
 * License: GPLv2
 * Text Domain: reward-for-comment
 * Domain Path: /languages
 */
 
 
/*  
    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*
This plugin is using public API of third-party service Cryptoo.me
	You can find the description of API at:
	https://cryptoo.me/api-doc/
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
 
if( ! class_exists('RFCX1994R_Reward_Comment_Plugin') ){

class RFCX1994R_Reward_Comment_Plugin {
	
	function __construct() {
		$this->comment_count = 0;
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'wp_ajax_RFCX1994R_get_satoshi_balance', array( $this, 'get_satoshi_balance' )); //admin base_url_action	
		add_action( 'wp_ajax_RFCX1994R_save_admin_settings', array( $this, 'save_admin_settings' )); //admin base_url_action	
		add_filter( "plugin_action_links_" . plugin_basename(  __FILE__ ), array( $this,'add_settings_link') );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts_and_styles' ),99999 );//trying to connect last because of ugly themes
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts_and_styles' ),99999 );
		add_filter( 'manage_edit-comments_columns', array($this,'process_comments_add_backend_column') );
		add_filter( 'manage_comments_custom_column',  array($this,'process_comments_backend_column'),10, 2 );
		add_action( 'wp_set_comment_status',  array($this,'process_change_comment_status'), 10, 2 );
		add_filter( 'comment_text', array( $this, 'process_parse_comments' ), 200, 2 ); 
		add_action( 'wp_ajax_RFCX1994R_send_reward', array( $this, 'send_reward' )); //admin base_url_action	
	}

	function send_reward(){ //admin only ajax!
		$ret = [comment_id=>0,sent=>0,error=>0,msg=>'',msg_type=>'success',msg_id=>md5(rand()),];
		if( !(in_array('administrator',  wp_get_current_user()->roles))){ 	//better safe than sorry
			$this->log('send_reward() called not from admin, POST:'); $this->log(sanitize_post($_POST));
			wp_die(1);
		}
		if( !(isset($_REQUEST)) ){
			$this->log('send_reward() empty REQUEST');
			wp_die(2);			
		}
		if(!(wp_verify_nonce($_REQUEST['nonce'], 'send_reward' )) ){
			$this->log('send_reward() nonce failed, POST:'); $this->log(sanitize_post($_POST));
			wp_die(3);
		}
		//ok, if we here we good to process
		$comment_id = intval($_REQUEST['comment_id']); //sanitized
		$amount = intval($_REQUEST['amount']);//sanitized

		$current_balance = get_option('rfcx_satoshi_balance',0 ); //need to notify admin
		$min_reward = get_option('rfcx_min_satoshi',1 ); //need to notify admin
		$max_reward = get_option('rfcx_max_satoshi',1000 );		 //need to notify admin
		$notify_admin = get_option('rfcx_notify_empty',true ); //need to notify admin
		$notify_commenter = get_option('rfcx_notify_reward',true );	 //need to notify commenter
		
		$ret['comment_id'] = $comment_id; //to output
		$wp_comment_object = get_comment( $id = $comment_id ); //$ret .= print_r($wp_comment_object,true);
		$to_address = $wp_comment_object->comment_author_email;
		$commenter_user_object = get_user_by('email', $to_address);// 
		$api_key = get_option('rfcx_satoshi_api_key',''); //to send satoshi
		$fields = array(
			'api_key'=> $api_key, 
			'to'=>$to_address,
			'amount'=>$amount,
		);	
		$response = wp_remote_post( 'https://cryptoo.me/api/v1/send', array(
							'method' => 'POST', 
							'body' => $fields ) );	
		$resp_body = wp_remote_retrieve_body( $response );
		$resp_code = wp_remote_retrieve_response_code( $response );		
		if($resp_code != "200"){ //bad htttp code
			$ret['msg'] = sprintf(__( 'Failed to send the reward to %s. Service did not respond, please try again later.','reward-for-comment'),$to_address); 
			$ret['msg_type'] =  'danger';
			$this->log("BAD wp_remote_retrieve_response_code: " . $resp_code);
		}else{ //code 200 - cryptoo.me is ok
			$resp = json_decode($resp_body);
			$this->log("SEND response:"); $this->log($resp);
			if($resp->status != "200" ){//failed to  send for some reason
				$ret['msg'] = sprintf(__( 'Failed to send the reward to %s.<br><b>%s</b>','reward-for-comment'),$to_address,$resp->message); 
				$ret['msg_type'] =  'danger';
			}else{//everythin is ok
				$paid_previously = $this->paid_anount( $comment_id);
				update_comment_meta( $comment_id, 'satoshi_reward', $paid_previously + $amount,$paid_previously);
				$ret['msg'] = sprintf(__( 'Commenter %s rewarded %u satoshi','reward-for-comment'),$to_address,$amount); 
				$ret['sent'] = $amount;//to update front-end
				if($notify_commenter){
					$email_res = $this->email_commenter($comment_id,$to_address);	
				}
			}//$resp->status
		}//http code 200
	
		wp_die(json_encode($ret));	
	}	//send_reward("save_admin_settings called not from admin") ajax

	function paid_anount($comment_id){ //pull from DB
		$ret = get_comment_meta( $comment_id, 'satoshi_reward', true );
		if(!ret){
			$ret = 0;
		}else{
			$ret = intval($ret);
		}
		return($ret);
	}//paid_anount
	
	function process_parse_comments( $comment_text, $comment) { //looks like $comment is never null
		if ( is_admin() ) {
			return $comment_text;
		}
		$ret = '';
		$comment_id = $comment->comment_ID; //$ret .= print_r($comment,true);
		$ret .= '<div class="RFCX1994R_inline_wrap">' . $this->get_comment_inline_stub($comment_id,true) .  $comment_text. '</div>';
		return($ret);
	}	//process_parse_comments
	
	
	function process_change_comment_status($process_change_comment_status, $comment_status){ //'hold', 'approve', 'spam', 'trash', or false.
		$this->log("process_change_comment_status() $process_change_comment_status  $comment_status" ); 
	}
	
	function process_comments_add_backend_column($columns){
		if(in_array('administrator',  wp_get_current_user()->roles)){ //admin
			$columns['rewards_column'] = __( 'Reward','reward-for-comment');
		}
		return $columns;
	}
	
	function process_comments_backend_column($column, $comment_id){
		if(in_array('administrator',  wp_get_current_user()->roles)){ //admin
			if ( 'rewards_column' == $column ) {
				if($this->comment_count == 0){
					$this->register_plugin_styles();
					echo('<script>var RFCX1994R_assets_path = "'.plugin_dir_url(__FILE__) . 'assets/";</script>');
				}		
				$suggest_mode = false; //TODO pay on approve / pay only manually  ($wp_comment_object->comment_approved == 1) 
				echo($this->get_comment_inline_stub($comment_id,false)); 
			}	
			$this->comment_count++;
		}
	} //process_comments_backend_column
	

	
	function make_coin($comment_id,$coin_html,$box_html){
		$admin_mode_title = '';
		if(in_array('administrator',  wp_get_current_user()->roles)){
			$admin_mode_title = __( 'You are in admin mode', 'reward-for-comment' );
		}
		$ret = '';
		$box_size = 40; //this.box_size x this.box_size 24px;36px;40px; etc.
		$horizontal_side = 'right'; //may be 'left';'right'
		$horizontal_offset = 10; //
		$vertical_side = 'top'; //may be 'top'; 'bottom'
		$vertical_offset = 10;//
		$style_mark_offset = ''.$horizontal_side.':'.$horizontal_offset.'px; '.$vertical_side.':'.$vertical_offset.'px; ';
		$style_mark = 'width:'.$box_size.'px; height:'.$box_size.'px; ' . $style_mark_offset;
		$ret .= '<div title="'.$admin_mode_title.'" data-toggle="tooltip" id="RFCX1994R_mark_'.$comment_id.'" class="RFCX1994R_coin" onclick="jQuery(\'#RFCX1994R_coin_box_'.$comment_id.'\').toggle(300);">'; // .animate({height: "toggle"}) | .toggle()
		$ret .= $coin_html;
		$ret .= '</div>';//box		
		$ret .= '<div id="RFCX1994R_coin_box_'.$comment_id.'" class="RFCX1994R_coin_box" >';
		$ret .= $box_html;
		$ret .= '</div>';//coin		
		return($ret);
	}//make_coin
		
	function get_system_not_ready_coin($comment_id){	
		$coin_html = '<div style="color:red;" title="'.__( 'To admin only', 'reward-for-comment' ).'" data-toggle="tooltip">' . __( 'System is not ready', 'reward-for-comment' ) .'</div>';
		$box_html .= '<div  class="alert alert-danger" title="'
				. __( 'System requires attention', 'reward-for-comment' )
				.'" data-toggle="tooltip" id="RFCX1994R_not_ready">'
				. __('To admin: Reward for Comment is not ready. Please', 'reward-for-comment' ) 
				. '<br><a href="'. admin_url('/options-general.php?page=reward-for-comment') . '">' . __( 'check the configuration', 'reward-for-comment' ) . '</>'
				. '</div>';	
		$ret = $this->make_coin($comment_id,$coin_html,$box_html);
		return($ret);
	}//get_system_not_ready_coin
		
	function make_other_user_coin($comment_id,$user_name,$user_email){	//always standard
		$coin_html = '<div class=" " title="'.__( 'Satoshi rewards', 'reward-for-comment' ).'" data-toggle="tooltip" ><span class="glyphicon  glyphicon-eye-close"></span></div>';	
		$box_html .= '<div  class="alert alert-info">'
				. __('Hello', 'reward-for-comment' )
				. ' ' . $user_name . '. <br>'
				. __('The actual reward amount is visible only to the commenter', 'reward-for-comment' ) . '.</div>';	
				$box_html .= '<a href="#respond">'.__('Post your own comment', 'reward-for-comment' ).'</a>';
				$box_html .= '<br>'. __('or', 'reward-for-comment' );
				$check_url = __('https://cryptoo.me/check/', 'reward-for-comment' ) . $user_email;
				$box_html .= '<br><a target=_blank href="'.$check_url.'">'.__('Check your balance', 'reward-for-comment' ).'</a>';
				$ret .= $this->make_coin($comment_id,$coin_html,$box_html);	
				return($ret);
	}//make_other_user_coin
	
	function make_guest_coin($comment_id){	//always standard
		$coin_html = '<div class="" title="'.__( 'Satoshi rewards', 'reward-for-comment' ).'" data-toggle="tooltip" >'.__('Rewards for comments', 'reward-for-comment' ).'</div>';	
		$box_html .= '<div  class="alert alert-info">'
				. __('Sorry, the reward details is for registered users only', 'reward-for-comment' ) . '.</div>';	
				$box_html .= ''. __('Please', 'reward-for-comment' );
				$box_html .= ' <a href="'.wp_login_url().'">'.__('Login', 'reward-for-comment').'</a>';
				$box_html .= ' '. __('or', 'reward-for-comment' );
				$box_html .= ' <a href="'.wp_registration_url().'">'.__('Register', 'reward-for-comment').'</a>';
				$ret .= $this->make_coin($comment_id,$coin_html,$box_html);	
				return($ret);
	}	//make_guest_coin
	
	function make_commenter_coin($comment_id,$paid_anount,$user_name,$user_email){	//
		$check_url = __('https://cryptoo.me/check/', 'reward-for-comment' ) . $user_email;
		if( ($paid_anount == 0)){ // not paid
			$coin_html = '<div class="RFCX1994R_admin_mark" title="'.__( 'Awaiting reward', 'reward-for-comment' ).'" data-toggle="tooltip" ><span class="glyphicon  glyphicon-hourglass"></span></div>';	
			$box_html .= '<div  class="alert alert-info ">';
			$box_html .= __('Please wait', 'reward-for-comment' ). ', ' . $user_name . '. <br>';
			$box_html .= __('This comment is not', 'reward-for-comment' );	
			$box_html .= ' <a target=_blank class="alert-link"  href="'.$check_url.'">'.__('rewarded', 'reward-for-comment' ).'</a> ';
			$box_html .= __('yet', 'reward-for-comment' ) . '.</div>';	
			$box_html .= '<a href="#respond">'.__('post new comment', 'reward-for-comment' ).'</a>';
		}else{ //paid, we good
			$coin_html = '<div class="RFCX1994R_admin_mark RFCX1994R_admin_mark_paid" title="'.__( 'Rewarded!', 'reward-for-comment' ).'" data-toggle="tooltip" >' . $paid_anount .'</div>';	
			$box_html .= '<div  class="alert alert-success" title="'.__( 'Click to the amount to check your balance', 'reward-for-comment' ).'" data-toggle="tooltip">';			
			$box_html .= __('Good news', 'reward-for-comment' ). ', ' . $user_name . '. <br>';
			$box_html .= __('Comment reward is ', 'reward-for-comment' );	
			$box_html .= ' <a target=_blank  class="alert-link" href="'.$check_url.'" title="'.__( 'Check your balance', 'reward-for-comment' ).'" data-toggle="tooltip">'.$paid_anount. ' ' . __('satoshi', 'reward-for-comment' ) . '</a> ';
			$box_html .= '.</div>';	
			$box_html .= '<a href="#respond">'.__('post another comment', 'reward-for-comment' ).'</a>';
		}
		$ret .= $this->make_coin($comment_id,$coin_html,$box_html);	
		return($ret);
	}	//make_commenter_coin
		
	function get_comment_inline_stub($comment_id,$is_frontend){
		$ret = ''; 
		if(!get_option('rfcx_system_ready',false)){
			if(is_admin() && in_array('administrator',  wp_get_current_user()->roles)){ //admin
				$ret .= $this->get_system_not_ready_coin($comment_id);
			}//if not admin - not showing anything
			return($ret);
		}
		$wp_comment_object = get_comment( $id = $comment_id ); //$ret .= print_r($wp_comment_object,true);
		$commenter_email = $wp_comment_object->comment_author_email;
		$commenter_user_object = get_user_by('email', $commenter_email);// $ret .= print_r($commenter_user_object,true);
		$comment_content = $wp_comment_object->comment_content;
		$suggest_amount = $this->suggest_amount($comment_content);
		$commenter_id = $commenter_user_object->ID;
		$paid_anount = $this->paid_anount($comment_id);

		if($paid_anount){//already paid 
			$suggest_amount = get_option('rfcx_min_satoshi');
		}
		$min_amount = get_option('rfcx_min_satoshi','1'); //must be already configured here
		$max_amount = get_option('rfcx_max_satoshi','1'); //must be already configured here
		
		if($is_frontend){
			if(in_array('administrator',  wp_get_current_user()->roles)){ //admin
				$coin_class = 'RFCX1994R_admin_mark_paid';//paid by default
				$paid_style = 'margin-bottom: 10px;';
				$waiting_style = 'display:none;';
				if( ($paid_anount == 0)){ // not paid
					$coin_class = 'RFCX1994R_admin_mark_unpaid';
					$waiting_style = 'margin-bottom: 10px;';
					$paid_style = 'display:none;';					
				}
				$box_html .= '<div style="'.$paid_style.'" id="RFCX1994R_rewarded_msg_'.$comment_id.'" class="alert alert-success" data-toggle="tooltip" title="'.$tooltip.'" >';
				$box_html .=  $commenter_email . ' ' . __( 'rewarded', 'reward-for-comment' ) . ' <span id="RFCX1994R_rewarded_'.$comment_id.'">' . $paid_anount .'</span> '. __( 'satoshi', 'reward-for-comment' );
				$box_html .= '</div>';	//alert	
				$box_html .= '<div style="'.$waiting_style.'" id="RFCX1994R_waining_msg_'.$comment_id.'" class="alert alert-warning" data-toggle="tooltip" title="'.$tooltip.'">';
				$box_html .= $commenter_email . ' ' . __( 'awaiting reward', 'reward-for-comment' );
				$box_html .= '</div>';	//alert					
				$coin_html = '<div id="RFCX1994R_admin_mark_text_'.$comment_id.'" class="RFCX1994R_admin_mark '.$coin_class.'" title="'.__( '', 'reward-for-comment' ).'" data-toggle="tooltip" >' . $paid_anount .'</div>';	
				$box_html .= $this->get_satoshi_amount_form($comment_id,$is_frontend,$suggest_amount,$min_amount,$max_amount);
				$on_click = 'RFCX1994R_send_reward(\''.admin_url('admin-ajax.php').'\',\''.wp_create_nonce( 'send_reward' ).'\','.$comment_id.', )';
				$box_html .= '<button onclick="'.$on_click.'" style="margin-top:3px;" id="RFCX1994R_pay_'.$comment_id.'" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Pay for comment', 'reward-for-comment' ).'" class="btn btn-success">'.__( 'Pay for comment', 'reward-for-comment' ).' <span class="glyphicon glyphicon glyphicon-menu-right"></span></button>';
				$ret .= $this->make_coin($comment_id,$coin_html,$box_html);
			}else{//not admin
				if( (wp_get_current_user()->ID == $commenter_id) && (wp_get_current_user()->ID != 0)){//commenter,not guest
					$ret .= $this->make_commenter_coin($comment_id,$paid_anount,wp_get_current_user()->display_name,wp_get_current_user()->user_email);
				}else{
					if(wp_get_current_user()->ID != 0){//other user
						$ret .= $this->make_other_user_coin($comment_id,wp_get_current_user()->display_name,wp_get_current_user()->user_email);
					}else{//guest
						$ret .= $this->make_guest_coin($comment_id); //we have nothing about this dude
					}
				}//commenter
			}//admin
		}else{//backend - admin NOT always
			if(in_array('administrator',  wp_get_current_user()->roles)){ //admin
				$waiting_style = 'margin-bottom: 10px;';
				$paid_style = 'display:none;';
				$tooltip = '';
				if($wp_comment_object->comment_approved != 1){
					$tooltip = '';
				}
				if($paid_anount > 0){ //paid
					$waiting_style = 'display:none;';
					$paid_style = 'margin-bottom: 10px;';
				}
				$box_html .= '<div style="'.$paid_style.'" id="RFCX1994R_rewarded_msg_'.$comment_id.'" class="alert alert-success" data-toggle="tooltip" title="'.$tooltip.'" >';
				$box_html .= __( 'Rewarded', 'reward-for-comment' ) . ' <span id="RFCX1994R_rewarded_'.$comment_id.'">' . $paid_anount .'</span> ' . __( 'satoshi', 'reward-for-comment' );
				$box_html .= '</div>';	//alert	
				$box_html .= '<div style="'.$waiting_style.'" id="RFCX1994R_waining_msg_'.$comment_id.'" class="alert alert-warning" data-toggle="tooltip" title="'.$tooltip.'">';
				$box_html .=   __( 'awaiting reward', 'reward-for-comment' );
				$box_html .= '</div>';	//alert				
			
				$box_html .= $this->get_satoshi_amount_form($comment_id,$is_frontend,$suggest_amount,$min_amount,$max_amount);
				$on_click = 'RFCX1994R_send_reward(\''.admin_url('admin-ajax.php').'\',\''.wp_create_nonce( 'send_reward' ).'\','.$comment_id.', )';
				$box_html .= '<button onclick="'.$on_click.'" id="RFCX1994R_pay_'.$comment_id.'" style="margin-top:3px;" id="RFCX1994R_pay" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Pay for comment', 'reward-for-comment' ).'" class="btn btn-success">'.__( 'Pay for comment', 'reward-for-comment' ).' <span class="glyphicon glyphicon glyphicon-menu-right"></span></button>';
				$ret .= $box_html; //$this->make_coin($comment_id,$coin_html,$box_html);
			}
		}
		return($ret);
	}//get_comment_inline_stub

	function get_satoshi_amount_form($comment_id,$is_frontend,$suggest_amount,$min_amount, $max_amount){
		$ret = '';
		$ret .= '<div id="RFCX1994R_satoshi_amount_form_'.$comment_id.'" class="RFCX1994R_satoshi_amount_form" >';

		$ret .= '<div class="1input-group">';
		$ret .= '<input style="width:100%;display:block;" id="RFCX1994R_reward_'.$comment_id.'" style="width:100%;" type="text" maxlength="10" size="10"  class="r2cx_num"  min="'.$min_amount.'" max="'.$max_amount.'" value="'.$suggest_amount.'" title="'. __( 'Satoshi amount to reward this comment', 'reward-for-comment' ) . ',' . $min_amount. ' - ' .$max_amount.'" placeholder="'. __( 'Satoshi amount to pay', 'reward-for-comment' ).'" data-toggle="tooltip" data-placement="left">';
		$ret .= '<button style="margin:1px;" onclick="RFCX1994R_bb(\'#RFCX1994R_reward_'.$comment_id.'\',-'.$max_amount.')" id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Minimum', 'reward-for-comment' ).'" class="btn-default btn btn-xs "><span class="glyphicon glyphicon-fast-backward"></span></button>';
		$ret .= '<button style="margin:1px;" onclick="RFCX1994R_bb(\'#RFCX1994R_reward_'.$comment_id.'\',-10)" id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Minus 10 satoshi', 'reward-for-comment' ).'" class="btn-default btn btn-xs "><span class="glyphicon glyphicon-backward"></span></button>';
		$ret .= '<button style="margin:1px;" onclick="RFCX1994R_bb(\'#RFCX1994R_reward_'.$comment_id.'\',-1)" id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Minus 1 satoshi', 'reward-for-comment' ).'" class="btn-default btn btn-xs "><span class="glyphicon glyphicon-minus"></span></button>';
		$ret .= '<button style="margin:1px;" onclick="RFCX1994R_bb(\'#RFCX1994R_reward_'.$comment_id.'\',+1)" id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Plus 1 satoshi', 'reward-for-comment' ).'" class="btn-default btn btn-xs "><span class="glyphicon glyphicon-plus"></span></button>';
		$ret .= '<button style="margin:1px;" onclick="RFCX1994R_bb(\'#RFCX1994R_reward_'.$comment_id.'\',+10)" id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" data-placement="bottom"  title="'.__( 'Plus 10 satoshi', 'reward-for-comment' ).'" class="btn-default btn btn-xs "><span class="glyphicon glyphicon-forward"></span></button>';
		$ret .= '<button style="margin:1px;" onclick="RFCX1994R_bb(\'#RFCX1994R_reward_'.$comment_id.'\',+'.$max_amount.')" id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" data-placement="bottom" title="'.__( 'Maximun', 'reward-for-comment' ).'" class="btn-default btn btn-xs "><span class="glyphicon glyphicon-fast-forward"></span></button>';			
		$ret .= '</div>';
		$ret .= '</div>';
		return($ret);
	}	//get_satoshi_amount_form
	
	function suggest_amount($text){
		$ret = 0;
		
		$char_price = get_option('rfcx_txt_satoshi',1) / get_option('rfcx_txt_chars',1);
		$no_ws_text = strip_tags(preg_replace('/[\t|\s{2,}]/','',$text)); //no tags, multiple white spaces tabs

		$ret = round($char_price * strlen($no_ws_text));
		
		$word_bonus = get_option('rfcx_extra_satoshi',1);
		$s_words = preg_replace('/\s*,\s*/', ',', get_option('rfcx_extra_words',''));//just in case
		$a_words = explode(',',$s_words);

		for($i = 0; $i < count($a_words); $i++){
			$ret += ($word_bonus * substr_count($text,$a_words[$i])); //original text! they may pay for tags
		}

		$imax = intval(get_option('rfcx_max_satoshi',1));
		$imin = intval(get_option('rfcx_min_satoshi',1));
		$ret = max($ret,$imin);
		$ret = min($ret,$imax);

		return($ret);
	}//suggest_amount
	
	function email_commenter($comment_id,$to_email){ 
		$comment_url = get_comment_link($comment_id);//need to notify commenter
		$subject = __('Reward for comment', 'reward-for-comment' );
		$body = __('Congratulations!', 'reward-for-comment' ) . '<br>';
		$body .= sprintf(__('The <a href="%s">comment</a> ', 'reward-for-comment' ), $comment_url) ;
		$body .= sprintf(__('has been <a href="https://cryptoo.me/check/%s">rewarded</a>. ', 'reward-for-comment' ), $to_email).'<br>';
		$body .= sprintf(__('Please <a href="%s">make more comments</a> for more rewards. ', 'reward-for-comment' ), '#respond').'<br>';		
		
		
		$body .= '<br><br>---<br>';
		$body .=  __('Sincerely yours', 'reward-for-comment' ).',<br>';
		$site_description = get_bloginfo('description');
		$site_title = get_bloginfo('name');
		$site_url = get_bloginfo('url');
		$body .= '<a href="'.$site_url .'">'. $site_title .'</a>';
	
		$headers = array(
			'content-type: text/html', //must have
		);
		add_filter( 'wp_mail_from_name', array( $this, 'email_replace_name_from' ));  
		$res = wp_mail( $to_email, $subject, $body , $headers );
		remove_filter( 'wp_mail_from_name', array( $this, 'email_replace_name_from' )); 
		$this->log("\n\nEmailing Commenter," . $to_email . "\n" . $subject . "\n" . $body . "\n returned " .print_r($res,true));		
		return($res);
	}//email_commenter()	
	function email_replace_name_from($from_name){//used via add_filter()
		return get_bloginfo('name'); //replace "WordPress" to site name. Headers do not work for some servers
	}

	function check_system_status(){ //called from front-end,returns true if we can run
		if(!get_option('rfcx_system_ready',false)){
			return(false);//never configured
		}
		$response = wp_remote_post( 'https://cryptoo.me/api/v1/balance', array(
			'method' => 'POST', 
			'body' => array('api_key'=> get_option('rfcx_satoshi_api_key',''),))  );	
		$resp_body = wp_remote_retrieve_body( $response );
		$resp_code = wp_remote_retrieve_response_code( $response );				
		if($resp_code != '200'){
			return(false);
		}else{ //code 200 - cryptoo.me is ok
			$resp = json_decode($resp_body);
			if($resp->status != '200' ){
				return(false);
			} else { //cryptoo.me returned balance
				$transaction_min = get_option('rfcx_min_satoshi',100);
				update_option('rfcx_satoshi_balance',$resp->balance);
				if(intval($resp->balance) < intval($transaction_min) ){
					 return(false); 
				}
				$transaction_max =  get_option('rfcx_max_satoshi',10000);
				if(intval($resp->balance) < intval($transaction_max) ){
					//we still can run, but the upper limit will be lower
				}	
				//if we here, we return available satoshi .check for locked ?
				return($resp->balance);
			}
		}
	}//check_system_status
	
	function render_options() {
		echo('<script>RFCX1994R_show_wait(true);</script>');
		echo( '<div id="RFCX1994R_top_alert" style="float:right;" class="alert alert-info"></div>') ; 
		
		echo('<h2>');
		_e( 'Reward for Comment', 'reward-for-comment' );
		echo('</h2>');
		echo( '<p>' );
		echo( '</p>' );
		echo('<script>
			var RFCX1994R_system_status = JSON.parse(\'' . $this->get_system_status(). '\');
			RFCX1994R_show_system_status = function(){
				jQuery("#RFCX1994R_top_alert").attr("class", "alert " + RFCX1994R_system_status.alert_type).html(RFCX1994R_system_status.message); 
				jQuery("#RFCX1994R_bottom_alert").attr("class", "alert " + RFCX1994R_system_status.alert_type).html(RFCX1994R_system_status.message); 
			}
			jQuery(document).ready(function() {RFCX1994R_show_system_status();});
		</script>');
		$this->register_plugin_styles();
		$this->render_payment_gateway_settings();
		$this->admin_api_key_js();
		$this->render_reward_settings();
		$this->render_save_settings();
	}//render_options
	
	function render_reward_settings() {
		$site_title = get_bloginfo(); //name by default
		$site_title = preg_replace("/[\pP]+/", ' ', $site_title);
		$site_title = preg_replace('/\s+/', ',',$site_title);
		$site_title = str_replace(' ', ' , ',$site_title);

		$adminsArray = get_site_option( 'site_admins', array( 'admin' ) );
		$admins = implode(',',$adminsArray);
//calculation html start
		echo('<div class="panel panel-default wrap wrap_calculation_settings">');
			echo('<div class="panel-heading">');
				echo( '<h3>' . __( 'Reward Calculation', 'reward-for-comment' ) . '</h3>'); 			
			echo('</div>');//panel-heading
			echo('<div class="panel-body">');	
			
			echo(__( 'See results of the Reward calculations at the', 'reward-for-comment' ) . '&nbsp;'  );
			$comment_page_link = admin_url('edit-comments.php');
			echo('&nbsp;<a target=_new href="'. $comment_page_link .'">'. __( 'Comments Page', 'reward-for-comment' ) . '</a>.');
			
			echo('<div class="well well-sm">
					<span >' . __( 'For comment text pay', 'reward-for-comment' ) . '</span>
					<input id="RFCX1994R_txt_satoshi" type="text" maxlength="4" size="3" r2cx_save="rfcx_txt_satoshi" class="r2cx_num" min="1" max="1000" value="'.get_option('rfcx_txt_satoshi','1').'" title="'. __( 'Satoshi value for basic calculation. Every valuable character in comment will be paid (satoshi/character) satoshi amount', 'reward-for-comment' ).'" data-toggle="tooltip">
					<span >' . __( 'satoshi for', 'reward-for-comment' ) . '</span>
					<input id="RFCX1994R_txt_chars" type="text" maxlength="4" size="3"  r2cx_save="rfcx_txt_chars" class="r2cx_num"  min="1" max="1000" value="'.get_option('rfcx_txt_chars','1').'" title="'. __( 'Character value for basic calculation. Every valuable character in comment will be paid (satoshi/character) satoshi amount', 'reward-for-comment' ).'" data-toggle="tooltip">
					<span >' . __( 'valuable characters', 'reward-for-comment' ) . '</span>
				</div>		
				
				<div class="well well-sm">
					<span >' . __( 'For a comment pay no less than', 'reward-for-comment' ) . '</span>
					<input id="RFCX1994R_min_satoshi" type="text" maxlength="4" size="3" r2cx_save="rfcx_min_satoshi" class="r2cx_num"  min="0" max="#RFCX1994R_max_satoshi" value="'.get_option('rfcx_min_satoshi','1').'" title="'. __( 'Every comment will be paid at list this value, even if basic calculation returns less', 'reward-for-comment' ).'" data-toggle="tooltip">
					<span >' . __( 'satoshi, and no more than', 'reward-for-comment' ) . '</span>
					<input id="RFCX1994R_max_satoshi" type="text" maxlength="4" size="3" r2cx_save="rfcx_max_satoshi" class="r2cx_num" min="#RFCX1994R_min_satoshi" max="10000" value="'.get_option('rfcx_max_satoshi','1000').'" title="'. __( 'Every comment can not be paid more than this amount, even if basic calculation returns more', 'reward-for-comment' ).'" data-toggle="tooltip">
					<span >' . __( 'satoshi', 'reward-for-comment' ) . '</span>
				</div>		

				<div class="well well-sm">
					<span >' . __( 'Pay extra', 'reward-for-comment' ) . '</span>
					<input id="RFCX1994R_extra_satoshi" type="text" maxlength="4" size="3" r2cx_save="rfcx_extra_satoshi" class="r2cx_num" min="0" max="10000"  value="'.get_option('rfcx_extra_satoshi','10').'" title="'. __( 'Pay extra for the words valuable for your website content', 'reward-for-comment' ).'" data-toggle="tooltip">
					<span >' . __( 'satoshi for words', 'reward-for-comment' ) . '</span>					  
					<input id="RFCX1994R_extra_words" type="text" size="50" r2cx_save="rfcx_extra_words"  class="form-control1"  value="'.get_option('rfcx_extra_words',$site_title .',[embed,satoshi').'" title="'. __( 'Comma-separated words triggering extra-payments', 'reward-for-comment' ).'" data-toggle="tooltip">
				</div>				
				  ');			
			echo('</div>');	//panel-body
		echo('</div>'); //panel
//calculation html end		

//notification html start

		echo('<div class="panel panel-default wrap wrap_notification_settings">');
			echo('<div class="panel-heading">');
				echo( '<h3>' . __( 'Notifications', 'reward-for-comment' ) . '</h3>'); 			
			echo('</div>');//panel-heading
			echo('<div class="panel-body">');	
			
			echo('
					<div class="checkbox" >
						<label title="'. __( 'Send email to the comment author when rewarded', 'reward-for-comment' ).'" data-toggle="tooltip"><input r2cx_save="rfcx_notify_reward" class="" type="checkbox" ' . (get_option('rfcx_notify_reward',true)?'checked="checked"':'') . ' value="" >'. __( 'Notify the commenter on the reward given', 'reward-for-comment' ) . '</label>
					</div>			
				  ');			
			echo('</div>');	//panel-body
		echo('</div>'); //panel
//notification html end
	}		//render_reward_settings
	
	function render_payment_gateway_settings() {
		echo('<div class="panel panel-default wrap wrap_api_key">');
			echo('<div class="panel-heading">');
				echo('<h3>' . __( 'Crypto-currency', 'reward-for-comment' ) . '</h3>'); 			
			echo('</div>');//panel-heading
			echo('<div class="panel-body">');
			
			echo('<div id="RFCX1994R_satoshi_system" class="input-group has-success has-feedback" >
					  <span class="input-group-addon">' . __( 'Payment system', 'reward-for-comment' ) . '</span>
					  <select  id="RFCX1994R_satoshi_system_selected" class="form-control" data-toggle="tooltip" title="' . __( 'Crypt-currency micropayment system', 'reward-for-comment' ) .'">
						<option id="RFCX1994R_system_cryptoome" value="cryptoome" selected  >Cryptoo.me</option>
						<option id="RFCX1994R_system_more" value="more" disabled >' . __( 'More coming soon', 'reward-for-comment' ) . '</option>
					  </select>
					</div>	
					<br>
				 ');

			
			echo(__( 'Get the API Key at the', 'reward-for-comment' ) . '&nbsp;'  );
			echo('<a target=_new href="'. __( 'https://cryptoo.me/applications/', 'reward-for-comment' ) .'">'. __( 'Application Manager', 'reward-for-comment' ).'</a>.');
			echo('&nbsp;(<a target=_new href="'. __( 'https://www.youtube.com/watch?v=-f5ckdopgag&list=PLRv0B44q8TR8bWrEwtMd6e17oW8wdRVIv&index=1', 'reward-for-comment' ) .'">'. __( 'HowTo Video', 'reward-for-comment' ) . '</a>)');
			
			echo('<div id="RFCX1994R_api_key_wrap" class="input-group has-error has-feedback" data-toggle="tooltip" title="' . __( 'Keep Secret !', 'reward-for-comment' ) .'">
					  <span class="input-group-addon">' . __( 'Cryptoo.me API Key', 'reward-for-comment' ) . '</span>
					  <input id="RFCX1994R_api_key"  type="text" maxlength="40" value="'.get_option('rfcx_satoshi_api_key','').'" class="form-control " r2cx_save="rfcx_satoshi_api_key"  name="api_key" placeholder="">
					  <span id="RFCX1994R_api_key_icon" class="glyphicon glyphicon-warning-sign form-control-feedback"></span>
					</div>		
					<br>
					<div class="input-group">
					  <button id="RFCX1994R_check_api_key" type="button" data-toggle="tooltip" title="'.__( 'Check the API Key', 'reward-for-comment' ).'" class="btn btn-success"><span class="glyphicon glyphicon-refresh"></span>&nbsp;'.__( 'Check the API Key', 'reward-for-comment' ).'</button>
					  <span  class="alert1 alert-danger1" id="RFCX1994R_key_check_result">...</span>
					</div>			
				 ');	
			echo('</div>');	//panel-body
		echo('</div>'); //panel
	}//render_reward_settings
	
	function admin_api_key_js(){ //returns Js to serve with base_url
		$ret = '
			<script>
			var s_e = "'.__( 'API Key is not valid', 'reward-for-comment' ).'";
			var s_e2 = "'.__( 'Something went wrong, try again!', 'reward-for-comment' ).'";
			var s_b = "'.__( 'Balance:', 'reward-for-comment' ).'";
			var s_s = "'.__( 'satoshi', 'reward-for-comment' ).'";			
			RFCX1994R_fetch_satoshi_balance = function(s_key){
				RFCX1994R_show_wait(true);
				jQuery("#RFCX1994R_key_check_result").html("...");
				jQuery("#RFCX1994R_check_api_key").prop("disabled", true);
				jQuery("#RFCX1994R_api_key").prop("disabled", true);
				jQuery.ajax({
					url : "'.admin_url('admin-ajax.php').'",
					type : "post",
					data : { 
						action : "RFCX1994R_get_satoshi_balance",
						api_key : s_key, 
					},
					success : function(response) {
						var ores = JSON.parse(response);
						var errProp = "error";
						if(ores.hasOwnProperty("error")){
							RFCX1994R_show_key_status(false);
							jQuery("#RFCX1994R_key_check_result").html(s_e2 +" (" + ores.error + ")");
						}else{
							if(ores.hasOwnProperty("balance")) {
								RFCX1994R_show_key_status(true);
								jQuery("#RFCX1994R_key_check_result").html(s_b + " " + ores.balance + " " + s_s);	 							
							}else{
								RFCX1994R_show_key_status(false);
								jQuery("#RFCX1994R_key_check_result").html(ores.message);							
							}
						}
						jQuery("#RFCX1994R_check_api_key").prop("disabled", false);
						jQuery("#RFCX1994R_api_key").prop("disabled", false);
						RFCX1994R_show_wait(false);
					},
					error: function(errorThrown){
						jQuery("#RFCX1994R_key_check_result").html(s_e2);
						RFCX1994R_show_key_status(false);
						jQuery("#RFCX1994R_check_api_key").prop("disabled", false);
						jQuery("#RFCX1994R_api_key").prop("disabled", false);
						RFCX1994R_show_wait(false);
						console.log("ERROR",errorThrown);						
					}							
				});	//ajax				
			}
			
			RFCX1994R_show_key_status = function(key_is_ok){
				RFCX1994R_highlight_valid(key_is_ok,"#RFCX1994R_api_key_wrap","#RFCX1994R_api_key_icon");
				if(!key_is_ok){
					jQuery("#RFCX1994R_key_check_result").html(s_e);
				}
			}
			
			RFCX1994R_key_check = function(s){
				var key_is_ok = false;
				if(s.length < 40){ //too short
					jQuery("#RFCX1994R_check_api_key").prop("disabled", true);
					RFCX1994R_show_key_status(false);
					RFCX1994R_show_wait(false);
				}else{//length is ok
					jQuery("#RFCX1994R_check_api_key").prop("disabled", false);
					RFCX1994R_fetch_satoshi_balance(s);
				}
			}
			jQuery(document).on("change keyup paste", "#RFCX1994R_api_key",function () {
				var s = jQuery(this).val();
				RFCX1994R_key_check(s);
			});	//RFCX1994R_num on	

			jQuery(document).on("click", "#RFCX1994R_check_api_key",function () {
				var s = jQuery("#RFCX1994R_api_key").val();
				RFCX1994R_fetch_satoshi_balance(s);
			});	//RFCX1994R_num on
			
			jQuery(document).ready(function() {
				RFCX1994R_key_check(jQuery("#RFCX1994R_api_key").val());
			})		
		</script>';
		echo($ret);
	}//admin_api_key_js
	
	function save_admin_settings(){ //admin only ajax!
		if(is_admin() && in_array('administrator',  wp_get_current_user()->roles)){ 	//better safe than sorry
			if ( isset($_REQUEST) ) {
				if(wp_verify_nonce($_REQUEST['nonce'], 'reward_for_comment' ) ){
					$j_vars = $_REQUEST['vars_to_save'];
					$a_vars = json_decode(stripslashes($j_vars));
					$this->log('save_admin_settings vars:'); $this->log($a_vars);
					for($i = 0; $i < count($a_vars); $i++){
						$name_val = $a_vars[$i];
						$name = sanitize_text_field($name_val->name); //sanitize name too!
						$val = $name_val->value;
						switch($name){
							case "rfcx_satoshi_api_key": update_option($name, sanitize_text_field($val)); break; //sanitized	
							case "rfcx_txt_chars": update_option($name, intval($val)); break; //sanitized	
							case "rfcx_txt_satoshi": update_option($name, intval($val)); break; //sanitized	
							case "rfcx_min_satoshi": update_option($name, intval($val)); break; //sanitized	
							case "rfcx_max_satoshi": update_option($name, intval($val)); break; //sanitized	
							case "rfcx_extra_satoshi": update_option($name, intval($val)); break; //sanitized	
							case "rfcx_extra_words": $val = preg_replace('/\s*,\s*/', ',', $val);update_option($name, $val); break;//live as is, tags allowed
							case "rfcx_notify_empty": update_option($name, boolval($val)); break; //sanitized	
							case "rfcx_notify_reward": update_option($name, boolval($val)); break;//sanitized	
						}
					}
					//here we saved everything from ajax. let's decide the system status		
					echo($this->get_system_status());
				}else{
					$this->log('save_admin_settings() nonce failed, POST:'); $this->log(sanitize_post($_POST));
					return '{"alert_type":"alert-danger","message":"nonce FAILED!"}';				
				}
			}//_REQUEST is set
		}else{ //is admin
			$this->log('save_admin_settings() called not from admin, POST:'); $this->log(sanitize_post($_POST));
		}
		wp_die();	
	}	//save_admin_settings("save_admin_settings called not from admin") ajax
	
	function get_system_status(){ //returns javascript object {alert_type, message}
		$extra_ret = ''; //we add balance there
		update_option('rfcx_system_ready', false); //by default

		$response = wp_remote_post( 'https://cryptoo.me/api/v1/balance', array(
			'method' => 'POST', 
			'body' => array('api_key'=> get_option('rfcx_satoshi_api_key',''),))  );	
		$resp_body = wp_remote_retrieve_body( $response );
		$resp_code = wp_remote_retrieve_response_code( $response );				
		if($resp_code != '200'){
			return '{"alert_type":"alert-danger","message":"'.__( 'Network error', 'reward-for-comment' ).'"}';
		}else{ //code 200 - cryptoo.me is ok
			$resp = json_decode($resp_body);
			if($resp->status != '200' ){
				return '{"alert_type":"alert-danger","message":"'.__( 'Cryptoo.me API Key is not configured', 'reward-for-comment' ). " (" . $resp->message . "[". $resp->status ."])". '"}';
			} else { //cryptoo.me returned balance
				update_option('rfcx_satoshi_balance',$resp->balance);
				$extra_ret = ',"balase":"' . $resp->balance.'"'; //so we can show it after save
				//done with satoshi stuff, let's check limits
				$transaction_min =  get_option('rfcx_min_satoshi',100);
				if($resp->balance < $transaction_min ){
					$msg = __( 'Available balance ', 'reward-for-comment' ) . ' (' . $resp->balance . ') ' . __( ' is under the minimal reward', 'reward-for-comment' ) . ' (' . $transaction_min . ')'; 
					return '{"alert_type":"alert-danger","message":"'. $msg .'"'. $extra_ret .'}';
				}
				$transaction_max =  get_option('rfcx_max_satoshi',10000);
				if($resp->balance < $transaction_max ){
					update_option('rfcx_system_ready', true); //keep operational, just a warninh
					$msg = __( 'Available balance ', 'reward-for-comment' ) . ' (' . $resp->balance . ') ' . __( ' is under maximal reward', 'reward-for-comment' ) . ' (' . $transaction_max . ')'; 
					return '{"alert_type":"alert-warning","message":"'. $msg .'"'. $extra_ret .'}';
				}				
			}
		}
	
		update_option('rfcx_system_ready', true);
		return '{"alert_type":"alert-success","message":"'. __( 'System is ready', 'reward-for-comment' ) .'"'. $extra_ret .'}';
	}//get_system_status
	
	function get_satoshi_balance(){ //admin only ajax! 
		if ( isset($_REQUEST) ) { //we do not save anything here, so no security checks
			$api_key = sanitize_text_field($_REQUEST['api_key']); //just in case
			$fields = array(
				'api_key'=> $api_key,
			);
	
			$response = wp_remote_post( 'https://cryptoo.me/api/v1/balance', array(
				'method' => 'POST', 
				'body' => $fields)  );
			$resp_body = wp_remote_retrieve_body( $response );
			$resp_code = wp_remote_retrieve_response_code( $response );

			if($resp_code != 200){ 
				echo('{"error":'.$resp_code.'}');
			}else{
				$resp = json_decode($resp_body);
				if($resp->status == '200' ){
					update_option('rfcx_satoshi_balance',$resp->balance);
				}
				echo($resp_body);
			}
		}
		wp_die();	
	}	//get_satoshi_balance() ajax

	function render_save_settings() {
		echo('<div class="panel panel-default wrap wrap_calculation_settings">');
			echo('<div class="panel-body">');	
			echo('<div class="well well-sm">
					  <button id="RFCX1994R_save" onclick="RFCX1994R_save_settings()" type="button" data-toggle="tooltip" title="'.__( 'Save settings before they take effect', 'reward-for-comment' ).'" class="btn btn-lg btn-success">
					  <span class="glyphicon glyphicon-saved"></span>&nbsp;'.__( 'Save Settings', 'reward-for-comment' ).'</button>
					  <span id="RFCX1994R_bottom_alert" class="alert alert-info"></span> 
					  <script>
						jQuery(document).ready(function(){
							jQuery(document).on("change keyup paste", "[r2cx_save]",function () {
								RFCX1994R_system_status = {alert_type:"alert-warning",message:"<span onclick=\"document.getElementById(\'RFCX1994R_save\').scrollIntoView()\">'.__( '<a>Save</a> to apply changes', 'reward-for-comment' ).'<span>"};
								RFCX1994R_show_system_status();
							})
						});					  
					  
						RFCX1994R_save_settings = function(){
							RFCX1994R_show_wait(true);
							var a_save = [];
							jQuery("[r2cx_save]").each(function(){
								var save_name = jQuery(this).attr("r2cx_save");
								var save_value = jQuery(this).val(); //for "text",textarea
								if(jQuery(this).attr("type") == "checkbox"){
									if(jQuery(this).is(":checked")){
										save_value = true;
									}else{
										save_value = false;
									}
								}
								a_save.push({"name":save_name,"value":save_value});
							});
							//console.log(a_save);							
							var j_save = JSON.stringify(a_save);
							jQuery.ajax({
								url : "'.admin_url('admin-ajax.php').'",
								type : "post",
								data : { 
									action : "RFCX1994R_save_admin_settings",
									nonce : "' . wp_create_nonce( 'reward_for_comment' ) . '",
									vars_to_save : j_save, 
								},
								success : function(response) {
									RFCX1994R_show_wait(false);
									RFCX1994R_system_status = JSON.parse(response);
									RFCX1994R_show_system_status();
									if(RFCX1994R_system_status.hasOwnProperty("balance")) {
										RFCX1994R_show_key_status(true);
										jQuery("#RFCX1994R_key_check_result").html(s_b + " " + RFCX1994R_system_status.balance + " " + s_s);
									}
									console.info("SUCCESS",response);
								},
								error: function(errorThrown){
									RFCX1994R_show_wait(false);
									alert("ERROR\n\n" + errorThrown)
									console.log("ERROR",errorThrown);						
								}							
							});	//ajax	
							
						}
					  </script>
				</div>		
				  ');			
			echo('</div>');	//panel-body
		echo('</div>'); //panel
echo('<center>' . __( 'Like this plugin', 'reward-for-comment' ) . ' ? ');		
echo('<a target=_blank href="https://wordpress.org/support/plugin/reward-for-comment/reviews?rate=5#new-post">' . __( 'Rate it &star;&star;&star;&star;&star;', 'reward-for-comment' ) . '</a> | ');
echo('<a target=_blank href="https://www.donationalerts.com/r/svinuga">' . __( 'Motivate the developer', 'reward-for-comment' ) . '</a> </center>');
	}//render_save_settings	
	
	
	


	function add_settings_link( $links ) {
		$img = '';
		$img = '<img style="vertical-align: middle;width:24px;height:24px;border:0;" src="'. plugin_dir_url( __FILE__ ) . 'images/icon1.png'.'"></img>';	
		$settings_link = '<a href="' . admin_url('/options-general.php?page=reward-for-comment') . '">' . $img . __( 'Settings' ) . '</a>';
		array_unshift($links , $settings_link);	
		return $links;
	}//add_settings_link()


	
	function init() {
		load_plugin_textdomain( 'reward-for-comment', FALSE, basename( dirname( __FILE__ ) ) . '/languages' );
	}
	
	function load_plugin_textdomain(){
//		load_plugin_textdomain( 'reward-for-comment', FALSE, basename( dirname( __FILE__ ) ) . '/languages' );
	}	

	function admin_menu() {
		add_options_page( __( 'Reward for Comment', 'reward-for-comment' ), __( 'Reward for Comment', 'reward-for-comment' ), 'manage_options', 'reward-for-comment', array( $this, 'render_options' ) );
	}
	
	function register_plugin_styles() {
		$this->bootstrap_scripts_and_styles();
//		$this->enqueue_scripts_and_styles();
		
	}	

	function enqueue_scripts_and_styles(){ 
		echo('<script>var RFCX1994R_assets_path = "'.plugin_dir_url(__FILE__) . 'assets/";</script>');
		wp_enqueue_style('reward-for-comment', plugin_dir_url(__FILE__) . 'assets/reward-for-comment.css');
		wp_enqueue_script('reward-for-comment', plugin_dir_url(__FILE__) . 'assets/reward-for-comment.js',array('jquery'));
	}//enqueue_scripts_and_styles()
	

	function bootstrap_scripts_and_styles(){	
		wp_register_style( 'bootstrap.min', plugin_dir_url(__FILE__) . 'assets/bootstrap.min.css' );
		wp_enqueue_style('bootstrap.min');
		wp_register_script( 'bootstrap.min', plugin_dir_url(__FILE__) . 'assets/bootstrap.min.js', array('jquery') );
		wp_enqueue_script('bootstrap.min');	
	}//bootstrap_scripts_and_styles()		
	
	function log($str_to_log){	
		//return; //before comment this inspect the source code! make sure you know what you doing ! do not use on life systems!
		$uploads = wp_upload_dir();
		$upload_path = $uploads['basedir'];	
		$file2 = $upload_path.'/r4c_log.txt';
		$flags = FILE_APPEND | LOCK_EX;
		$date = date('d-m-Y h:i:s', time());
		if (is_array($str_to_log) || is_object($str_to_log)) {
               $str_to_log = print_r($str_to_log, true);
        }
		file_put_contents($file2, $date."\n".$str_to_log.' ', $flags);
	}	
	
	function admin_init() {
		register_setting( 'Reward_for_Comment_Options', 'rfcx_satoshi_api_key' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_satoshi_balance' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_txt_chars' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_txt_satoshi' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_min_satoshi' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_max_satoshi' );		
		register_setting( 'Reward_for_Comment_Options', 'rfcx_notify_empty' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_notify_reward' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_extra_satoshi' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_extra_words' );
		register_setting( 'Reward_for_Comment_Options', 'rfcx_system_ready' ); //set programmatically
	}
	
	
} //close for class  RFCX1994R_Reward_Comment_Plugin

$GLOBALS['feward-for-comments'] = new RFCX1994R_Reward_Comment_Plugin;


}// class_exists('RFCX1994R_Reward_Comment_Plugin')