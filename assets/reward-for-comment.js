/*
Reward for Comment JS

*/
//console.info('reward-for-comment.js LOADED');
var RFCX1994R_msg_wrap = 0;
var RFCX1994R_send_reward = function(admin_url,nonce,comment_id){
	var amount = jQuery('#RFCX1994R_reward_'+comment_id).val();
	jQuery('#RFCX1994R_reward_'+comment_id).val(); 
	jQuery('#RFCX1994R_pay_'+comment_id).prop("disabled", true);
console.log('RFCX1994R_send_reward()',admin_url,nonce,comment_id);
	RFCX1994R_show_wait(true);
	jQuery.ajax({
		url : admin_url, 
		type : "post",
		data : { 
			action : "RFCX1994R_send_reward",
			nonce : nonce,
			comment_id : comment_id, 
			amount : amount,
		},
		success : function(response) {
			RFCX1994R_show_wait(false);
			jQuery('#RFCX1994R_pay_'+comment_id).prop("disabled", false);
			var ret = JSON.parse(response);
console.info("SUCCESS",response,ret);
			if(RFCX1994R_msg_wrap == 0){
				RFCX1994R_msg_wrap = document.createElement("div");
				RFCX1994R_msg_wrap.innerHTML = '<div id="RFCX1994R_msg_wrap" style="position:fixed;right:50px; top:50px; width:30%;"></div>';				
				document.body.appendChild(RFCX1994R_msg_wrap);
			}
			var msg = document.createElement("div");
			var alert_id = 'RFCX1994R_' + ret.msg_id;
			var alert_html = '';
			alert_html += '<div style="display:none" class="alert fade in alert-dismissible alert-'+ret.msg_type+'" id='+alert_id+' role="alert">';
			alert_html += '<a href="#" class="close" data-dismiss="alert" aria-label="close">&times;</a>';
			alert_html += ret.msg;
			alert_html += '</div>';
			jQuery('#RFCX1994R_msg_wrap').html(jQuery('#RFCX1994R_msg_wrap').html() + alert_html);
			jQuery('#'+alert_id).show('slow');
			setInterval(function(){jQuery('#'+alert_id).fadeOut('slow')} ,10000); //hide
			var id_box = '#RFCX1994R_coin_box_'  + ret.comment_id;
			jQuery(id_box).toggle(300);
			if(ret.sent > 0){ //we did send something
				var id_to_hide = '#RFCX1994R_waining_msg_' + ret.comment_id;
				var id_to_show = '#RFCX1994R_rewarded_msg_'  + ret.comment_id;
				var id_to_amount = '#RFCX1994R_rewarded_'  + ret.comment_id;
				var id_coin_text = '#RFCX1994R_admin_mark_text_'  + ret.comment_id;
				jQuery(id_to_amount).html(parseInt(jQuery(id_to_amount).html()) + ret.sent);
				jQuery(id_to_hide).slideUp();
				jQuery(id_to_show).slideDown();
				jQuery(id_coin_text).html(parseInt(jQuery(id_coin_text).html()) + ret.sent);
				jQuery(id_coin_text).addClass('RFCX1994R_admin_mark_paid').removeClass('RFCX1994R_admin_mark_unpaid');
			}
		},
		error: function(errorThrown){
			RFCX1994R_show_wait(false);
			jQuery('#RFCX1994R_pay_'+comment_id).prop("disabled", false);
			alert("ERROR\n\n" + errorThrown)
			console.log("ERROR",errorThrown);						
		}							
	});	//ajax	
}//RFCX1994R_send_reward

if(typeof document.createStyleSheet === 'undefined') {
    document.createStyleSheet = (function() {
        function createStyleSheet(href) {
            if(typeof href !== 'undefined') {
                var element = document.createElement('link');
                element.type = 'text/css';
                element.rel = 'stylesheet';
                element.href = href;
            }
            else {
                var element = document.createElement('style');
                element.type = 'text/css';
            }

            document.getElementsByTagName('head')[0].appendChild(element);
            var sheet = document.styleSheets[document.styleSheets.length - 1];

            if(typeof sheet.addRule === 'undefined')
                sheet.addRule = addRule;

            if(typeof sheet.removeRule === 'undefined')
                sheet.removeRule = sheet.deleteRule;

            return sheet;
        }

        function addRule(selectorText, cssText, index) {
            if(typeof index === 'undefined')
                index = this.cssRules.length;

            this.insertRule(selectorText + ' {' + cssText + '}', index);
        }

        return createStyleSheet;
    })();
}


var RFCX1994R_show_wait = function(do_show){
//console.log('RFCX1994R_show_wait()',do_show,RFCX1994R_show_wait.caller);
	if(do_show){
		if(jQuery('.RFCX1994R_global_loader').length === 0){//nott inserted yet
			var wrap = document.createElement("div");
			wrap.innerHTML = '<div class="RFCX1994R_global_loader"></div>';				
			document.body.appendChild(wrap);		
		}
		jQuery("body").css("cursor", "progress");
		jQuery('.RFCX1994R_global_loader').show();
	}else{
		jQuery('.RFCX1994R_global_loader').hide();
		jQuery("body").css("cursor", "default");
	}
	setTimeout(function(){	jQuery('[data-toggle="tooltip"]').tooltip();  },500); 
}//ACF1984S_show_wait()

var RFCX1994R_validate_email = function(desc){
    var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var is_valid = re.test(jQuery(desc).val());
//console.log(jQuery(desc).val(),is_valid);	
	return is_valid;
}

var RFCX1994R_highlight_valid = function(is_valid,wrap_desc,icon_desc){
	if(is_valid){
		if(jQuery(wrap_desc).hasClass("has-error")){jQuery(wrap_desc).removeClass("has-error");jQuery(wrap_desc).addClass("has-success");}
		if(jQuery(icon_desc).hasClass("glyphicon-warning-sign")){jQuery(icon_desc).removeClass("glyphicon-warning-sign");jQuery(icon_desc).addClass("glyphicon-ok");}
	}else{ //not ok
		if(jQuery(wrap_desc).hasClass("has-success")){jQuery(wrap_desc).removeClass("has-success");jQuery(wrap_desc).addClass("has-error");}
		if(jQuery(icon_desc).hasClass("glyphicon-ok")){jQuery(icon_desc).removeClass("glyphicon-ok");jQuery(icon_desc).addClass("glyphicon-warning-sign");}					
	}
	return is_valid;
}

var RFCX1994R_validate_email_highlight = function(desc,wrap_desc,icon_desc){
	var RFCX1994R_is_valid = RFCX1994R_validate_email(desc);
	RFCX1994R_highlight_valid(RFCX1994R_is_valid,wrap_desc,icon_desc);
	return RFCX1994R_is_valid;
}

var RFCX1994R_bb = function(desc,change_val){//box button
	var umax = parseInt(jQuery(desc).attr("max"));
	var umin = parseInt(jQuery(desc).attr("min"));
	var cur_val = parseInt(jQuery(desc).val());
	var new_val = cur_val + change_val;
	if(new_val > umax)new_val = umax;
	if(new_val < umin)new_val = umin;
	jQuery(desc).val(new_val);
} 

jQuery(document).ready(function () {
//console.info('reward-for-comment.js DOCUMENT READY');
	if( 1  || (typeof jQuery.fn.modal == 'undefined' ) && (typeof jQuery().emulateTransitionEnd != 'function') ){ //no bootstrap
		jQuery.getScript(RFCX1994R_assets_path+'bootstrap.min.js');
		document.createStyleSheet(RFCX1994R_assets_path+'bootstrap.min.css');	
		console.log('+++Bootstrap loaded dynamically');
	}else{
		console.log('---Bootstrap is present','typeof jQuery.fn.modal',(typeof jQuery.fn.modal),jQuery.fn.modal);
	}
//console.log('glyphicon:',jQuery('.glyphicon').css('font-family') );
	
	var RFCX1994R_timer = setInterval(function(){
		if(jQuery.fn.modal){
			jQuery('[data-toggle="tooltip"]').tooltip(); 
			clearInterval(RFCX1994R_timer);
		}
	},500); 
	
	jQuery(".r2cx_num").on('change keyup paste cut delete', function () {
		var umin = jQuery(this).attr("min");
		if(!isNaN(umin)){
			umin = parseInt(umin);
		}else{
			umin = parseInt(jQuery(umin).val());
		}
		
		var umax = jQuery(this).attr("max"); 
		if(!isNaN(umax)){
			umax = parseInt(umax);
		}else{
			umax = parseInt(jQuery(umax).val());	
		}
//console.log("ppse_num umin:",umin," umax:", umax);
		var s = jQuery(this).val();
		var n = s.replace(/[^0-9]/g,'');

		if(n.length == 0) n = umin;
		n = parseInt(n);
		if(n < umin) n = umin;
		if(n > umax) n  = umax;
		jQuery(this).val(n);	
//console.log("ppse_num",umin,umax,n,this);			
	});
	
	jQuery(".r2cx_cur").on('change keyup paste cut delete', function () {
		var umin = jQuery(this).attr("min");
		if(!isNaN(umin)){
			umin = parseFloat(umin);
		}else{
			umin = parseFloat(jQuery(umin).val());
		}
		
		var umax = jQuery(this).attr("max"); 
		if(!isNaN(umax)){
			umax = parseFloat(umax);
		}else{
			umax = parseFloat(jQuery(umax).val());	
		}

		var s = jQuery(this).val();
		var n = s.replace(/[^0-9.-]+/,'');

		if(n.length == 0){
			n = umin;
		}
		n = parseFloat(n);
	
		if(n < umin){
			n = umin;
		}
		if(n > umax){
			n  = umax;
		}
//console.log("ppse_cur umin:",umin, "umax:",umax,s,n);			
		jQuery(this).val(n.toFixed(2));	
	});	
})