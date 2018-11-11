;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";

  Drupal.pmm.collections = {};

  /*
   * Threads collection.
   */
  Drupal.pmm.collections.Threads = Backbone.Collection.extend({
    model: Drupal.pmm.models.Thread,
    url: Drupal.pmm.helpers.buildReqUrl('threads', {limit: Drupal.pmm.settings.threadCount}),
    parse: function(resp, xhr) {
      // Set if selected based on current path, if selected unread should be false.
      resp = _.map(resp, function(item){
        item.selected = (window.location.hash === '#' + item.id);
        item.unread = (item.selected ? false : item.unread);
        return item;
      });
      return resp;
    }
  });

  /*
   * Messages collection.
   */
  Drupal.pmm.collections.Messages = Backbone.Collection.extend({
    model: Drupal.pmm.models.Message,
    url: Drupal.pmm.helpers.buildReqUrl('messages', {})
  });

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);
