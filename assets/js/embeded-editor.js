(function($){

  var template_id;

  var guest_id    = dh_woo_ee_object.guest_id;
  var user_id     = dh_woo_ee_object.user_id;
  var user_token  = dh_woo_ee_object.user_token;
  var admin_url   = dh_woo_ee_object.ajax_url;
  var store_url   = dh_woo_ee_object.store_url;
  var api_domain  = dh_woo_ee_object.store_domain;


  // Get Template ID
  $('.variations_form').on('update_variation_values', function(){
    setTimeout(function(){
      if( $('.single_variation').is(':visible') ){
        $('.woocommerce-variation-add-to-cart').hide();
        $('.dh-editor-btn-wrap').slideDown(100);

        template_id = $('#dh-template').data('id');
      } else {
        $('.dh-editor-btn-wrap').hide();
      }
      $('#dh-project-btn').text('Customize This Design');
    }, 300);
  });


  // AJAX call to create project
  $('#dh-project-btn').click(function(e){
    e.preventDefault();

    $.blockUI();

    // generate token for guest only, logged user token will generate from backend
    if( user_token == undefined ){
      if( Cookies.get('dh_guest_id') == undefined ){
        guest_id = dh_woo_ee_object.guest_id;
        Cookies.set('dh_guest_id', guest_id, { expires: 7, sameSite: 'lax' });
      }

      // console.log('guest_id: '+guest_id,'user_id: '+user_id,'user_token: '+user_token);

      $.post(
        admin_url,
        {
          'action'    : 'dh_user_token',
          'guest_id'  : guest_id,
          'user_id'   : user_id
        },
        function(response) {
          var data = JSON.parse(response);

          if( data.access_token == undefined ){
            alert(data.error);
          } else {
            user_token = data.access_token;
            Cookies.set('dh_user_token', user_token, { expires: data.expires_in, sameSite: 'lax' });

            selectProject( user_token, template_id );
          }
        }
      );
    } else {
      selectProject( user_token, template_id );
    }
  });


  // Edit Project from Cart
  $('body').on('click', '.dh-project-cart', function(e){
    e.preventDefault();

    $.blockUI();

    var project_id = $(this).data('project');
    var cart_item = $(this).parents('.cart_item');

    // generate token for guest only, logged user token will generate from backend
    if( user_token == undefined ){
      if( Cookies.get('dh_guest_id') == undefined ){
        guest_id = dh_woo_ee_object.guest_id;
        Cookies.set('dh_guest_id', guest_id, { expires: 7, sameSite: 'lax' });
      }

      $.post(
        admin_url,
        {
          'action'    : 'dh_user_token',
          'guest_id'  : guest_id,
          'user_id'   : user_id
        },
        function(response) {
          var data = JSON.parse(response);

          if( data.access_token == undefined ){
            alert(data.error);
          } else {
            user_token = data.access_token;
            Cookies.set('dh_user_token', user_token, { expires: data.expires_in, sameSite: 'lax' });

            openModal( project_id, user_token, cart_item );
          }
        }
      );
    } else {
      openModal( project_id, user_token, cart_item );
    }
  });

/*
  $(document).ready(function(){
    if( projects = dh_woo_ee_object.dh_projects ){ 
      Object.entries(projects).forEach(([key, val]) => {
        Cookies.set('dh_project_linked_' + key, val, { sameSite: 'lax' });
      });
    }

    if(window.location.href.indexOf('&dh_project_id=') != -1){
      setTimeout(function(){
        template_id = $('#dh-template').data('id');
        $('#dh-project-btn').text('Edit Your Design');
        $('.woocommerce-variation-add-to-cart, .dh-editor-btn-wrap').show();
      }, 300 );
    }
  });
*/

  function selectProject( user_token, template_id ){
    var project_id;
    // console.log('User Code: '+ user_id);
    // console.log('User Token: '+ user_token);

    DSHDEditorLib.configure({
      domain: api_domain,
      access_token: user_token
    });

    DSHDEditorLib.createProject({
      template_id: template_id,
    }, function(error, data){
      if (error) {
        console.log(error);
        $.unblockUI();
      } else {
        project_id = data.project_id;          
        openModal( project_id, user_token );
      }
    });

/*
    DSHDEditorLib.getProjects( {
      limit: 20,
      page: 1
    }, function(error, data){
      if (error) {
        console.log(error);
        $.unblockUI();
      } else {
        var create = true;
        // console.log(data.total);
        if( data.total ){
          var projects = data.items;
          for (var p in projects) {
            var project = projects[p];
            if( project.source_template.source_template_id == template_id){
              project_id = project.project_id;
              // console.log(project_id);
              create = false;
              openModal( project_id, user_token );
              break;
            }
          }
        }

        if( create ){
          DSHDEditorLib.createProject({
            template_id: template_id,
          }, function(error, data){
            if (error) {
              console.log(error);
              $.unblockUI();
            } else {
              project_id = data.project_id;          
              openModal( project_id, user_token );
            }
          });
        }
      }
    } );
*/

  }


  function openModal( project_id, user_token, cart_item = null ){
    // console.log(project_id);
    $.magnificPopup.open({
      items: {
        src: store_url +'/editor?project_id='+ project_id +'&token='+ user_token,
      },
      type: 'iframe',
      callbacks: {
        afterClose: function(){
          $.post(
            admin_url,
            {
              'action' : 'dh_get_thumbnail',
              'project_id' : project_id
            },
            function(response){
              var content = JSON.parse(response);
              // console.log( content.data.user );

              if( content.success ){
                if( $('#dh_project_id').length ){
                  $('#dh_project_id').val(project_id);
                  $('#dh_project_thumbnail').val(content.data.thumbnail_url);

                  $('.flex-active-slide a').attr('href', content.data.thumbnail_url).html('<img src="'+ content.data.thumbnail_url + '" class="project-image">');
                  
                  $('#dh-project-btn').text('Edit Your Design');
                  $('.woocommerce-variation-add-to-cart').show();
                } else {
                  cart_item.find('img').attr('src', content.data.thumbnail_url);
                }
              } else {
                alert(data.message);
              }

              $.unblockUI();
            }
          );
        }
      }
    });
  }

})(jQuery);