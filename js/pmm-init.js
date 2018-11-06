;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";


  // Use Drupal.pmm as our global namespace.
  // --------------------------------------------
  if (Drupal.pmm === undefined) {
    Drupal.pmm = {};
  }


  // Settings, vars, constants.
  // --------------------------------------------
  Drupal.pmm.settings = {
    urlThreadPrefix: 'thread',
    urlNewPrefix: 'new',
    getEndpoint: drupalSettings.path.baseUrl + 'pmm-get/',
    postEndpoint: drupalSettings.path.baseUrl + 'pmm-post/',
    widthLarge: 700,
    openFirstThread: false,
    enterKeySend: true,
    messengerSelector: '.pmm-messenger',
    maxMembers: 0,
    access: false,
    token: '',
    lastCheckTimestamp: 0,
    threadCount: 30,
    ajaxRefreshRate: 15,
    recentAjaxRefreshRate: 15,
    recentThreadCount: 3,
    messengerPath: '/messenger',
    messengerActive: false,
    unreadCount: 0
  };

  // Settings passed via drupalSettings can overwrite hard coded settings.
  // Extending modules can use `hook_private_message_messenger_js_settings_alter()`.
  if (drupalSettings.pmm) {
    Drupal.pmm.settings = $.extend(Drupal.pmm.settings, drupalSettings.pmm);
  }


  // Helpers.
  // --------------------------------------------
  Drupal.pmm.helpers = {};

  /**
   * Format time as string.
   */
  Drupal.pmm.helpers.formatTimeString = function(dateString) {
    var date = new Date(dateString), out = '';
    return date.toLocaleDateString();
  };

  /**
   * Return a timestamp suitable for cache busting.
   */
  Drupal.pmm.helpers.getTimestamp = function() {
    var d = new Date(); return Math.round((d.getTime() / 1000));
  };

  /**
   * Return a timestamp suitable for cache busting.
   */
  Drupal.pmm.helpers.getToken = function() {
    return Drupal.pmm.settings.token;
  };

  /**
   * Build request params..
   */
  Drupal.pmm.helpers.buildReqParams = function(params) {
    params = params || {};
    params.tok = Drupal.pmm.settings.token;
    params.t = Drupal.pmm.helpers.getTimestamp();
    return params;
  };

  /**
   * Build request url.
   */
  Drupal.pmm.helpers.buildReqUrl = function(endpoint, params, type) {
    var base = (type === undefined || type == 'get' ? Drupal.pmm.settings.getEndpoint : Drupal.pmm.settings.postEndpoint);
    return base + endpoint + '?' + $.param(Drupal.pmm.helpers.buildReqParams(params));
  };

  /**
   * Navigate to thread id.
   */
  Drupal.pmm.helpers.navigateToThread = function(id) {
    Drupal.pmm.helpers.navigateTo(id);
  };

  /**
   * Change the url hash to indicate nav and provide history.
   */
  Drupal.pmm.helpers.navigateTo = function(path) {
    if(history.pushState) {
      history.pushState(null, null, '#' + path);
    }
    else {
      location.hash = '#' + path;
    }
    $(window).trigger('hashchange');
    Drupal.pmm.helpers.setSelectedThread();
  };

  /**
   * Check if a url path starts with a given hash path.
   */
  Drupal.pmm.helpers.urlStartsWith = function(path) {
    return window.location.hash.lastIndexOf('#' + path, 0) === 0;
  };

  /**
   * Is the current url a thread path.
   *
   * Eg #thread-1234 returns true.
   */
  Drupal.pmm.helpers.isThreadUrl = function() {
    return Drupal.pmm.helpers.urlStartsWith(Drupal.pmm.settings.urlThreadPrefix);
  };

  /**
   * Is the current url a new thread path.
   *
   * Eg #new or #new-1234 returns true.
   */
  Drupal.pmm.helpers.isThreadNewUrl = function() {
    return Drupal.pmm.helpers.urlStartsWith(Drupal.pmm.settings.urlNewPrefix);
  };

  /**
   * Is the current url the inbox.
   *
   * Eg #inbox or null hash.
   */
  Drupal.pmm.helpers.isInboxUrl = function() {
    return window.location.hash == '' || Drupal.pmm.helpers.urlStartsWith('inbox');
  };

  /**
   * Get an Id from a url has with a given prefix.
   */
  Drupal.pmm.helpers.getIdFromUrl = function(prefix) {
    var hash = window.location.hash, id;
    id = parseInt(hash.replace('#' + prefix + '-', ''));
    return isNaN(id) ? false : id;
  };

  /**
   * Get thread id from url hash.
   *
   * Eg #thread-1234 returns 1234
   */
  Drupal.pmm.helpers.getUrlThreadId = function() {
    return Drupal.pmm.helpers.getIdFromUrl(Drupal.pmm.settings.urlThreadPrefix);
  };

  /**
   * Get uid from new thread url hash or false if no uid.
   *
   * Eg. #new-1234 returns 1234 or #new returns false.
   */
  Drupal.pmm.helpers.getUrlNewUid = function() {
    return Drupal.pmm.helpers.getIdFromUrl(Drupal.pmm.settings.urlNewPrefix);
  };

  /**
   * Set selected thread based on url.
   */
  Drupal.pmm.helpers.setSelectedThread = function() {
    var action = 'removeClass';
    $('#pmm-threads .pmm-thread-teaser').each(function(){
      action = ('#' + $(this).attr('rel') === window.location.hash) ? 'addClass' : 'removeClass';
      $(this)[action]('selected');
    });
  };

  /**
   * Binds to message form. Deals with focus, sending with enter, etc.
   *
   * @param $wrapper
   *   Wrapper containing the textarea (eg the thread view el).
   */
  Drupal.pmm.helpers.formBinds = function($wrapper) {
    // A defer is required so binds get applied correctly in the stack.
    _.defer(function($wrapper) {
      $('textarea', $wrapper).focus()
        .keyup(function(e){
          if (Drupal.pmm.settings.enterKeySend && (e.which == 13 && !e.shiftKey) && Drupal.pmm.helpers.isDesktop()) {
            e.preventDefault();
            $(this).closest('form').find('.js-form-submit').click();
          }
        });
    }, $wrapper);
  };

  /**
   * Toggle if the form is disabled (while sending a message).
   *
   * @param $form
   *   jQuery Form element.
   * @param disabled
   *   True or false.
   */
  Drupal.pmm.helpers.setFormDisabled = function($form, disabled) {
    if (disabled) {
      $form.addClass('disabled').find('textarea').attr('disabled', 'disabled');
    } else {
      $form.removeClass('disabled').find('textarea').removeAttr('disabled');
    }
  };

  /**
   * Scroll the thread messages to the bottom of the list (showing most recent msg).
   *
   * @param $el
   *   Optional $el that exists in dom, if empty, will attempt to use selector.
   */
  Drupal.pmm.helpers.scrollThreadToLastMsg = function($el) {
    $el = $el || $('#pmm-thread .message-list');
    $el.scrollTop($el[0].scrollHeight);
  };

  /**
   * Open first thread from a thread collection.
   */
  Drupal.pmm.helpers.openFirstThread = function(collection) {
    // If setting enabled, on desktop and hash is empty (ie path = /messenger)
    if (
      Drupal.pmm.settings.openFirstThread &&
      Drupal.pmm.helpers.isDesktop() &&
      window.location.hash === '' &&
      collection.first()
    ) {
      Drupal.pmm.helpers.navigateToThread(collection.first().get('id'));
    }
  };

  /**
   * Return the unix timestamp for the last visible message in the current thread.
   */
  Drupal.pmm.helpers.getLastVisibleMsgTimeStamp = function() {
    var lastTime = $('#pmm-thread .pmm-message').last().data('time');
    var d = new Date(lastTime); return (d.getTime() / 1000);
  };

  /**
   * Add nicer timestamps if timeago is available.
   */
  Drupal.pmm.helpers.applyTimeAgo = function($wrapper) {
    if (typeof timeago === 'function') {
      $('.pmm-timestamp', $wrapper).once('pmm-timestamp').each(function(i, el){
        timeago().render(el);
      });
    }
  };

  /**
   * Save a Message.
   *
   * @param $form
   *   The new message $form.
   */
  Drupal.pmm.helpers.saveMessage = function($form, callback) {
    $(window).trigger('pm:threads:viewed');
    var $msg = $('textarea', $form), values = $form.serialize();
    if ($msg.val() === '' || $('.pmm-member', $form).length === 0 || $form.hasClass('disabled')) {
      Drupal.pmm.helpers.formBinds($form);
      return;
    }

    // Set form as disabled to prevent double submit.
    Drupal.pmm.helpers.setFormDisabled($form, true);

    // Get time of last post
    values += '&timestamp=' + Drupal.pmm.helpers.getLastVisibleMsgTimeStamp();

    // Post & Save.
    $.post(Drupal.pmm.helpers.buildReqUrl('message', {}, 'post'), values, function(data) {
      Drupal.pmm.helpers.formBinds($form);
      Drupal.pmm.helpers.setFormDisabled($form, false);
      if (data && data.thread) {
        $msg.val('');
        callback(data);
      }
      else {
        alert('There was an issue saving this message to save message');
      }
    });
  };

  /**
   * Callback used by the selectize member selector to update members in a new thread form.
   *
   * @param $form
   *   The new message $form.
   * @param items
   *   Array of uids.
   */
  Drupal.pmm.helpers.updateMembers = function($form, items) {
    // Create a base hidden field el and remove existing members.
    var $hiddenField = $('<input>').attr('name', 'members[]').attr('type', 'hidden').addClass('pmm-member');
    $('.pmm-member', $form).remove();

    // If items selected, create a hidden el for each.
    if (items && items.length) {
      $.each(items, function(i, uid){
        $form.append($hiddenField.clone().attr('value', uid));
      })
    }
  };

  /**
   * Returns an array of members from a thread model as a string.
   *
   * @param members
   *   Array of members.
   * @param asLinks
   *   If true, each member will be a link, else just plain text.
   */
  Drupal.pmm.helpers.membersToString = function(members, asLinks) {
    var out = [];
    for (var i = 0, len = members.length; i < len; i++) {
      if (asLinks) {
        out.push('<a href="' + members[i].url + '">' + members[i].name + '</a>');
      }
      else {
        out.push(members[i].name);
      }
    }
    return out.join(', ');
  };

  /**
   * Get a count of threads which have been updated since timestamp.
   */
  Drupal.pmm.helpers.checkForThreadUpdates = function(callback) {
    var timestamp = Drupal.pmm.settings.lastCheckTimestamp;
    var url = Drupal.pmm.helpers.buildReqUrl('poll', {ts: timestamp});
    $.getJSON(url, function(data) {
      Drupal.pmm.settings.lastCheckTimestamp = Drupal.pmm.helpers.getTimestamp();
      if (data && data.c && parseInt(data.c) > 0) {
        callback(data);
      }
    });
  };

  /**
   * Check if desktop (large) mode. Returns true if desktop mode.
   */
  Drupal.pmm.helpers.isDesktop = function() {
    var $wrapper = $(Drupal.pmm.settings.messengerSelector);
    return ($wrapper.width() > Drupal.pmm.settings.widthLarge);
  };

  /**
   * Apply the 'pmm-small' class for small screens.
   */
  Drupal.pmm.helpers.checkWidth = function() {
    var $wrapper = $(Drupal.pmm.settings.messengerSelector);
    if (Drupal.pmm.helpers.isDesktop()) {
      $wrapper.removeClass('pmm-small').addClass('pmm-large');
    } else {
      $wrapper.addClass('pmm-small').removeClass('pmm-large');
    }
  };

  /**
   * Get ajax refresh rate, default is the recent block refresh rate, but if
   * on the messenger page, its refresh rate trumps the recent block. This allows
   * for more frequent checking on the messenger page.
   */
  Drupal.pmm.helpers.getRefreshRate = function() {
    return (Drupal.pmm.settings.messengerActive ?
      Drupal.pmm.settings.ajaxRefreshRate : Drupal.pmm.settings.recentAjaxRefreshRate);
  };


  // Generic behaviours.
  // --------------------------------------------

  /**
   * On messenger block ready and in dom.
   */
  Drupal.behaviors.pmmMessengerReady = {
    attach: function attach(context) {
      $(Drupal.pmm.settings.messengerSelector, context).once('pmm').each(function () {

        // Add initialized class once js kicked in.
        $(Drupal.pmm.settings.messengerSelector).addClass('initialized');

        // Add size based classes on load and resize.
        Drupal.pmm.helpers.checkWidth();
        $(window).on('resize', _.debounce(function () {
          Drupal.pmm.helpers.checkWidth();
        }, 250));
      });
    }
  };

  /**
   * Global behavior.
   *
   * Main purpose is polling which applies to recent and messenger.
   */
  Drupal.behaviors.pmmGlobal = {
    attach: function attach(context) {
      $('body', context).once('pmm-polling').each(function () {

        // If user does not have access to use private messages we should not poll.
        if (Drupal.pmm.settings.access === false) {
          return;
        }

        // Poll for new updates.
        Drupal.pmm.settings.lastCheckTimestamp = Drupal.pmm.helpers.getTimestamp();
        // Only if refresh rate is gt 0.
        if (Drupal.pmm.helpers.getRefreshRate() > 0) {
          setInterval(function(e){
            Drupal.pmm.helpers.checkForThreadUpdates(function(data){
              $(window).trigger('pm:threads:updated', [data]);
            });
          }, (Drupal.pmm.helpers.getRefreshRate() * 1000));
        }

        // An external trigger has asked us to poll for messages.
        $(window).on('pm:threads:poll', function(e) {
          Drupal.pmm.helpers.checkForThreadUpdates(function(data){
            $(window).trigger('pm:threads:updated', [data]);
          });
        });

      });

      // Close any dropdowns on body click.
      $('body').once('pmm-close-dropdown').click(function(e){
        $('.pmm-dropdown-parent').removeClass('open');
      });
    }
  };

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);