jQuery(document).ready( function($){ 

	//no referrer
	if ( document.referrer.length == 0 )
		return;
	
	//referrer already checked
	if ( $.cookie('wp_referrer_check') == document.referrer )
		return;
		
	//send ping
	$.ajax({
		url: '?pingback&postID=' + pingbacks_plus.postID + '&referrer=' + document.referrer,
		success: function() {
			$.cookie( 'wp-referrer-check', document.referrer );
		}
	});
	
});