# Private Message Messenger

Private messages in Drupal 8, that look like sweet!

This is "Facebook messenger" style UX for the [Private Message module](https://www.drupal.org/project/private_message).

The whole UX is build using Backbone & Marionette, making it a snappy single page app. View messages, chnage threads,
compose and reply to messages without a single page reload.

It is build with mobile in mind and will switch between mobile/desktop layout as space provides.

## Screenshots

#### Desktop

![Desktop](https://preview.ibb.co/jHm7bb/messenger_desktop.jpg)

#### Mobile

![Inbox](https://image.ibb.co/c38aUw/messenger_mobile_inbox.jpg)

![thread](https://image.ibb.co/bSsfwb/messenger_mobile_thread.jpg)

## Dependencies

### Private Message

The [Private Message](https://www.drupal.org/project/private_message) module provides the framework for this module
to work. It does a great job of handling message and thread structure using entites.

### MarionetteJS

This module utilizes MarionetteJS to improve on BackboneJS capabilities. As of writing, it is not on drupal.org yet.
Grab it from: https://github.com/jez500/Drupal8-Marionette-JS

This module was built using Marionette `v3.5.1`, download the zip from https://marionettejs.com/download/ and extract
into your `/libraries` folder.

### Selectize

The selectize JS library is used for adding new recipients to messages, install the
[Selectize drupal module](https://www.drupal.org/project/selectize) and download the
[library](https://github.com/selectize/selectize.js/releases) to your `/libraries` folder.

## Installation

**Composer only instructions until I work out what to do with this module.**

#### 1. Add repo and install Private Message Messenger with deps

Add the repo to your `composer.json``

```
    "repositories": [
        ...

        {
            "type": "git",
            "url": "https://github.com/jez500/Drupal8-Marionette-JS.git"
        },
        {
            "type": "git",
            "url": "https://github.com/jez500/Private-Message-Messenger.git"
        }
    ],

```

Then...
`composer require drupal/marionettejs`

And...
`composer require drupal/private_message_messenger`

#### 2. Enable

`drush en -y private_message_messenger`

#### 3. Configure

* Go to `/admin/config/private_message/config` and set preferred configuration then `Save`
* Set the permission to `use private message` for the required roles.
* Visit `/messenger` to see the messenger page
* Optional: You can messenger as a block to any page.

Docs for private_message: https://www.drupal.org/docs/8/modules/private-message

## Paths

* Thread path `/messenger#thread-[THREAD_ID]` eg `/messenger#thread-1`
* Compose new message path `/messenger#new`
* Compose new message to a specific user `/messenger#new-[UID]` eg `/messenger#new-1]`
* Inbox (Only really applies to mobile, but will also refresh inbox) `/messenger#new`

## Optional

### Timeago

To make the dates on threads and messages look a lot nicer and use a live `XX mins ago` style format, you can include
the [timeago library](http://timeago.org/) in your theme, along with a behaviour that looks like this:

```
  Drupal.behaviors.timeAgoUpdate = {
    attach: function attach(context) {
      $('.pmm-timestamp', context).once('pmm-timestamp').each(function(){
        timeago().render(this);
      });
    }
  }
```

## Todo's / Wishlist

* Look into token validation
* Add delete thread option
* Email - Correct url / allow override of email notification template
* Add a flag that registers if a message is viewed but not actioned, a message should not need a reply to flag as
read - possible improvement to private_message, or maybe achievable with flag
* Create a private_message_nodejs module that eliminates the need for polling and provides instant updates.
* Pagination for thread list and message list
  * Currently thread list is limited to specified config
  * Currently ALL messages are shown in a loaded thread, have tested with 100's of messages and JSON response is still
  very small eg < 30k. More of a problem could potentially be the browser rendering that much, but no issues observed
  yet.

