jQuery(document).ready(function($) {

    $('.hc-username').click(function( event ){
        event.preventDefault();

        var username = $(this).data("username");

        var mla_user_id = $(this).data("mla_user_id");
        var hc_user_id = $(this).data("hc_user_id");


        var data = {
            action: 'change_username',
            username: username,
            mla_user_id: mla_user_id,
            hc_user_id: hc_user_id
        };

        // since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
        $.post(ajaxurl, data, function(response) {
            $("a.hc-username").hide();
            $("div#changeUsername").append(response);
        });
    });
});

