;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";

  Drupal.behaviors.pmmRecent = {

    /**
     * Dom ready.
     */
    attach: function attach(context) {
      $('#pmm-recent', context).once('pmm-recent').each(function () {
        var self = this,
          $body = $('body');

        // Set unread count.
        Drupal.behaviors.pmmRecent.setUnreadCount(Drupal.pmm.settings.unreadCount, self);

        // Instantiate a new thread collection.
        Drupal.pmm.collections.threadsRecentInstance = new Drupal.pmm.collections.Threads();

        // Instantiate a new thread view with the collection instance.
        Drupal.pmm.views.threadRecentListInstance = new Drupal.pmm.views.ThreadRecentList({
          'collection': Drupal.pmm.collections.threadsRecentInstance
        });

        // On link click, open drop-down and fetch recent messages.
        $('.pmm-dropdown-toggle', self).click(function(e){
          e.preventDefault();
          e.stopPropagation();
          var $wrapper = $(this).closest('.pmm-recent');
          $wrapper.toggleClass('open');
          if ($wrapper.hasClass('open')) {
            // Hide unread count.
            $('.pmm-recent-count', self).hide();
            // Fetch results if opened.
            Drupal.pmm.collections.threadsRecentInstance.fetch({
              data: {limit: Drupal.pmm.settings.recentThreadCount, read: 1}
            });
          }
        });

        // Listen for threads updates and re-fetch if there is updates.
        $(window).on('pm:threads:updated', function(e, data) {
          Drupal.behaviors.pmmRecent.setUnreadCount(data.c, self);
          // Fetch to mark items as read if required.
          Drupal.pmm.collections.threadsRecentInstance.fetch({
            data: {limit: Drupal.pmm.settings.recentThreadCount}
          });
        });

        // Listen for threads viewed to clear the count badge.
        $(window).on('pm:threads:viewed', function(e){
          Drupal.behaviors.pmmRecent.setUnreadCount(0, self);
        });

      });
    },

    /**
     * Set the unread count badge.
     *
     * @param count
     *   Count of unreads.
     * @param context
     *   Dom context.
     */
    setUnreadCount: function(count, context) {
      var $el = $('.pmm-recent-count', context);
      count = parseInt(count);
      if (count > 0) {
        count = (parseInt(count) > 99) ? '99+' : count;
        $el.show().html(count);
      } else {
        $el.hide()
      }
    }
  };

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);
