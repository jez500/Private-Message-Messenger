;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";

  Drupal.behaviors.pmmTreads = {
    attach: function attach(context) {
      $('#pmm-threads', context).once('pmm-threads').each(function () {

        // Instantiate a new thread collection.
        Drupal.pmm.collections.threadsInstance = new Drupal.pmm.collections.Threads();

        // Instantiate a new thread view with the collection instance.
        Drupal.pmm.views.threadListInstance = new Drupal.pmm.views.ThreadList({
          'collection': Drupal.pmm.collections.threadsInstance
        });

        // Open thread on click.
        Drupal.pmm.views.threadListInstance.on('childview:select:item', function(item, event){
          Drupal.pmm.helpers.navigateTo(item.model.get('id'));
          $(window).trigger('pm:threads:viewed');
        });

        // Set the optional uid so new users appear in thread list.
        var fetchOptions = {},
          uid = Drupal.pmm.helpers.getUrlNewUid();
        if (uid) {
          fetchOptions = {data: {uid: uid}};
        }

        // Fetch results.
        Drupal.pmm.collections.threadsInstance.fetch(fetchOptions);

        // Fetch results if route changes.
        $(window).on('pm:route:change', function(e, data) {
          Drupal.pmm.collections.threadsInstance.fetch();
        });

        // Listen for threads updates and re-fetch if there is updates.
        $(window).on('pm:threads:updated', function(e, data) {
          Drupal.pmm.collections.threadsInstance.fetch();
        });
      });
    }
  };

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);
