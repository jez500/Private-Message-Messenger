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
          $(window).trigger('threads:viewed');
        });

        // Set the optional uid so new users appear in thread list.
        var fetchOptions = {},
          uid = Drupal.pmm.helpers.getUrlNewUid();
        if (uid) {
          fetchOptions = {data: {uid: uid}};
        }

        // Fetch results.
        Drupal.pmm.collections.threadsInstance.fetch(fetchOptions);

        // Listen for threads updates and re-fetch if there is updates.
        $(window).on('threads:updated', function(e, data) {
          // Loop over the models in the threads collection.
          Drupal.pmm.collections.threadsInstance.each(function(model){
            // If one of those models is in the collection, re-fetch.
            if ($.inArray(parseInt(model.get('threadId')), data.t) !== -1) {
              model.fetch();
            }
          })
        });
      });
    }
  };

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);