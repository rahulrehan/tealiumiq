(function($, Drupal, drupalSettings) {
  Drupal.behaviors.tealiumiqWebformAjaxSubmit = {
    attach: function(context, settings) {
      $.fn.tealiumiqWebformAjaxSubmit = function(tags) {
        // Send the tags to Tealium.
        utag.view(tags);
      };
    }
  };
})(jQuery, Drupal, drupalSettings);
