;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";

  Drupal.pmm.models = {};

  /*
   * Thread model.
   */
  Drupal.pmm.models.Thread = Backbone.Model.extend({
    url: function() {
      if (this.get('type') == 'thread') {
        return Drupal.pmm.helpers.buildReqUrl('thread', {id: this.get('id')});
      }
      else {
        return Drupal.pmm.helpers.buildReqUrl('member', {uid: this.get('id')});
      }
    },
    defaults: {
      id: 0,
      type: 'thread',
      threadId: 0,
      members: [],
      picture: '',
      owner: '',
      snippet: '',
      timestamp: '',
      url: '',
      unread: false,
      messages: [],
    }
  });

  /*
   * Message model.
   */
  Drupal.pmm.models.Message = Backbone.Model.extend({
    defaults: {
      id: 0,
      owner: '',
      picture: '',
      is_you: false,
      message: '',
      timestamp: '',
    }
  });


})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);