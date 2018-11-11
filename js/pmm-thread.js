;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";

  Drupal.behaviors.pmmThread = {

    /**
     * Attach to dom and init.
     */
    attach: function attach(context) {
      $('#pmm-thread', context).once('pmm-threads').each(function () {
        // Initial load.
        Drupal.behaviors.pmmThread.routeChange();

        // If there has been a hash change.
        $(window).on('hashchange', function() {
          Drupal.behaviors.pmmThread.routeChange();
        });

        // Listen for message updates to current thread.
        $(window).on('pm:threads:updated', function(e, data) {
          if (Drupal.pmm.helpers.isThreadUrl()) {
            // If we are on one of the updated threads, recent messages using timestamp.
            var threadId = Drupal.pmm.helpers.getUrlThreadId();
            if ($.inArray(threadId, data.t) !== -1) {
              var opt = {data: {id: threadId, ts: data.ts}, add: true, remove: false};
              Drupal.pmm.collections.messagesInstance.fetch(opt);
              $(window).trigger('pm:threads:viewed');
            }
          }
        });
      });
    },

    /**
     * Called on route change or initial load.
     */
    routeChange: function() {
      $(Drupal.pmm.settings.messengerSelector).removeClass('is-inbox');
      $(window).trigger('pm:route:change');

      // If inbox.
      if (Drupal.pmm.helpers.isInboxUrl()) {
        Drupal.behaviors.pmmThread.renderInbox();
      }
      else {
        // Not inbox.
        if (Drupal.pmm.helpers.isThreadUrl()) {
          // If thread url, we know there is a thread id.
          Drupal.behaviors.pmmThread.renderThread();
        }
        else if (Drupal.pmm.helpers.isThreadNewUrl()) {
          var uid = Drupal.pmm.helpers.getUrlNewUid();

          // If uid is known, render a thread, specifying the uid.
          if (uid) {
            Drupal.behaviors.pmmThread.renderThread('new', uid);
          }
          else {
            // Otherwise supply a empty thread form with user selector.
            Drupal.behaviors.pmmThread.renderThreadNew();
          }
        }
      }
    },

    /**
     * Render a thread based on a thread id or user id.
     *
     * @param type
     *   Either 'thread' for an existing thread or 'new' for a new thread for a known uid.
     * @param id
     *   A thread id or a uid based on type.
     */
    renderThread: function(type, id) {
      type = type || 'thread';

      // If thread url, and no thread_id specified, populate.
      if (id == undefined && Drupal.pmm.helpers.isThreadUrl()) {
        id = Drupal.pmm.helpers.getUrlThreadId();
      }

      // Destroy any existing views.
      if (Drupal.pmm.views.threadInstance) {
        Drupal.pmm.views.threadInstance.destroy();
      }

      // Instantiate a new message collection.
      Drupal.pmm.collections.messagesInstance = new Drupal.pmm.collections.Messages();

      // Instantiate a new thread model and fetch.
      var threadModel = new Drupal.pmm.models.Thread({id: id, type: type});
      threadModel.fetch();

      // We need the model before render so bind thread render to model sync.
      threadModel.on('sync', function(){
        // Create a new thread view with model and collection.
        Drupal.pmm.views.threadInstance = new Drupal.pmm.views.Thread({
          model: threadModel,
          collection: Drupal.pmm.collections.messagesInstance,
        });

        // Toggle thread actions menu.
        Drupal.pmm.views.threadInstance.on('render', function(e, view){
          $('.pmm-dropdown-toggle', this.$el).click(function(e){
            e.stopPropagation();
            $(this).closest('.pmm-dropdown-parent').toggleClass('open');
          });
        });

        // Render it to the dom.
        var $thread = Drupal.pmm.views.threadInstance.render().$el;
        $('#pmm-thread').html($thread);

        // Bind form submit to save a new message.
        Drupal.pmm.views.threadInstance.on('form:submit', function(item, event){
          Drupal.pmm.helpers.saveMessage($('form', item.$el), function(data){
            // Add new messages to collection and scroll to it.
            Drupal.pmm.collections.messagesInstance.add(data.messages);
            Drupal.pmm.helpers.scrollThreadToLastMsg();
            // Refresh sidebar.
            Drupal.pmm.collections.threadsInstance.fetch();
          });
        });
      });
    },

    /**
     * Render the new thread view.
     *
     * This is used when user is not known and includes user selector.
     */
    renderThreadNew: function() {
      // Destroy any existing views.
      if (Drupal.pmm.views.threadNewInstance) {
        Drupal.pmm.views.threadNewInstance.destroy();
      }

      // Get a new thread view (empty with user chooser).
      Drupal.pmm.views.threadNewInstance = new Drupal.pmm.views.ThreadNew();

      // Render it to the dom.
      var $threadNew = Drupal.pmm.views.threadNewInstance.render().$el;
      $('#pmm-thread').html($threadNew);

      // Bind form submit to save a new message.
      Drupal.pmm.views.threadNewInstance.on('form:submit', function(item, event){
        Drupal.pmm.helpers.saveMessage($('form', item.$el), function(data){
          // Go to the new thread.
          Drupal.pmm.helpers.navigateToThread(data.thread.id);
          // Refresh sidebar.
          Drupal.pmm.collections.threadsInstance.fetch();
        });
      });
    },

    /**
     * Add 'is-inbox' class and fetch on inbox.
     */
    renderInbox: function() {
      $(Drupal.pmm.settings.messengerSelector).addClass('is-inbox');
      if (Drupal.pmm.collections.threadsInstance) {
        Drupal.pmm.collections.threadsInstance.fetch();
      }
    }

  };

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);
