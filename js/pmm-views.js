;(function (Backbone, $, Drupal, drupalSettings, _, Mn) {

  "use strict";

  Drupal.pmm.views = {};


  // Base views with generic defaults.
  // --------------------------------------------

  /**
   * Base view for Marionette.View().
   */
  Drupal.pmm.views.ViewBase = Mn.View.extend({
    onRender: function() {
      Drupal.attachBehaviors(this.el);
    }
  });

  /**
   * Base view for Marionette.CollectionView().
   */
  Drupal.pmm.views.CollectionViewBase = Mn.CollectionView.extend({
    onRender: function() {
      Drupal.attachBehaviors(this.el);
      Drupal.pmm.helpers.applyTimeAgo(this.$el);
      $('.pmm-loading', this.$el).hide();
    }
  });


  // PMM Views.
  // --------------------------------------------

  /**
   * Empty view.
   */
  Drupal.pmm.views.Empty = Drupal.pmm.views.ViewBase.extend({
    template: _.template('')
  });

  /**
   * Empty view for recent messages.
   */
  Drupal.pmm.views.EmptyRecent = Drupal.pmm.views.ViewBase.extend({
    tagName: 'li',
    template: _.template('No recent messages')
  });

  /**
   * Thread teaser view.
   */
  Drupal.pmm.views.ThreadTeaser = Drupal.pmm.views.ViewBase.extend({
    tagName: 'li',
    template: '#pmm-thread-teaser',
    triggers: {
      'click .pmm-thread-teaser': 'select:item'
    },
    modelEvents: {
      'change': 'render'
    }
  });

  /**
   * Thread recent teaser view.
   */
  Drupal.pmm.views.ThreadRecentTeaser = Drupal.pmm.views.ThreadTeaser.extend({
    template: '#pmm-thread-teaser-recent',
    triggers: {},
    events: {
      'click .pmm-thread-teaser': 'goToThread'
    },
    goToThread: function() {
      window.location = Drupal.pmm.settings.messengerPath + '#thread-' + this.model.get('threadId');
    }

  });

  /**
   * Thread teaser list.
   */
  Drupal.pmm.views.ThreadList = Drupal.pmm.views.CollectionViewBase.extend({
    el: '#pmm-threads',
    childView: Drupal.pmm.views.ThreadTeaser,
    emptyView: Drupal.pmm.views.Empty,
    collectionEvents: {
      "sync": "render"
    },
    onRender: function() {
      Drupal.pmm.views.CollectionViewBase.prototype.onRender.call(this);
      Drupal.pmm.helpers.setSelectedThread();
    }
  });

  /**
   * Recent thread teaser list.
   */
  Drupal.pmm.views.ThreadRecentList = Drupal.pmm.views.ThreadList.extend({
    el: '#pmm-recent-threads',
    childView: Drupal.pmm.views.ThreadRecentTeaser,
    emptyView: Drupal.pmm.views.EmptyRecent
  });

  /**
   * Message teaser view.
   */
  Drupal.pmm.views.Message = Drupal.pmm.views.ViewBase.extend({
    tagName: 'li',
    template: '#pmm-message'
  });

  /**
   * Message list.
   */
  Drupal.pmm.views.Messages = Drupal.pmm.views.CollectionViewBase.extend({
    tagName: 'ul',
    className: 'message-list',
    childView: Drupal.pmm.views.Message,
    emptyView: Drupal.pmm.views.Empty,
    collectionEvents: {
      "sync": "render"
    },
    onRender: function() {
      // Scroll to the bottom of the list.
      Drupal.pmm.helpers.scrollThreadToLastMsg(this.$el);
      Drupal.attachBehaviors(this.el);
    }
  });

  /**
   * Thread Wrapper (Contains a child view of Messages).
   *
   * We don't specify `el` because this view is destroyed and rebuilt many
   * times in its lifecycle. Instead its rendered `$el` is added via
   * $('#pmm-thread').html() after render.
   */
  Drupal.pmm.views.Thread = Drupal.pmm.views.ViewBase.extend({
    template: '#pmm-thread-full',
    regions: {
      messages: {
        el: '.pmm-thread__messages',
        replaceElement: true
      }
    },
    triggers: {
      'click .js-form-submit': 'form:submit'
    },
    onRender: function() {
      // Fetch collection and render child view only if a thread.
      if (this.model.get('type') == 'thread') {
        this.collection.fetch({data: {id: this.model.get('threadId')}});
        this.showChildView('messages', new Drupal.pmm.views.Messages({
          collection: this.collection
        }));
      }
      Drupal.attachBehaviors(this.el);
    },
    modelEvents: {
      'change': 'fetchMessages'
    },
    fetchMessages: function() {
      // If the model has been updated, re-fetch the messages.
      if (this.model.get('type') == 'thread') {
        this.collection.fetch({data: {id: this.model.get('threadId')}});
      }
    }
  });

  /**
   * New Thread Wrapper (for starting a new thread with new members).
   */
  Drupal.pmm.views.ThreadNew = Drupal.pmm.views.ViewBase.extend({
    template: '#pmm-thread-new',
    triggers: {
      'click .js-form-submit': 'form:submit'
    },
    onRender: function() {
      // New member selectize widget.
      $('.new-message-members', this.$el).selectize({
        plugins: ['remove_button', 'restore_on_backspace'],
        create: false,
        labelField: 'username',
        valueField: 'uid',
        searchField: ['username'],
        maxItems: (Drupal.pmm.settings.maxMembers == 0 ? 1000 : Drupal.pmm.settings.maxMembers),
        preload: true,
        placeholder: 'Type a username',
        load:  function(query, callback) {
          var url = Drupal.pmm.helpers.buildReqUrl('members', {name: query});
          $.getJSON(url, function(data) {
            callback(data);
          });
        },
        onChange: function(val) {
          Drupal.pmm.helpers.updateMembers(this.$input.closest('.pmm-thread').find('form'), this.items);
        }
      });
      Drupal.attachBehaviors(this.el);
    }
  });

})(Backbone, jQuery, Drupal, drupalSettings, _, Marionette);