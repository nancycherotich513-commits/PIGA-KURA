(function () {
  'use strict';

  // Ask before destructive actions: <button data-confirm="Are you sure?">
  document.addEventListener('click', function (event) {
    var el = event.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) {
      event.preventDefault();
    }
  });

  // An election's end can never be earlier than its start
  var start = document.querySelector('input[name="start_at"]');
  var end = document.querySelector('input[name="end_at"]');
  if (start && end) {
    var syncEnd = function () {
      if (start.value) {
        end.min = start.value;
        if (end.value && end.value <= start.value) {
          end.value = '';
        }
      }
    };
    start.addEventListener('change', syncEnd);
    syncEnd();
  }

  // Show a preview of the candidate photo before it is uploaded
  var photo = document.getElementById('photo');
  var preview = document.getElementById('photo-preview');
  if (photo && preview) {
    photo.addEventListener('change', function () {
      var file = photo.files && photo.files[0];
      if (!file) {
        preview.style.display = 'none';
        return;
      }
      preview.src = URL.createObjectURL(file);
      preview.style.display = 'block';
    });
  }

  // Fade out flash messages after a few seconds
  document.querySelectorAll('.flash').forEach(function (el) {
    setTimeout(function () { el.classList.add('fade-out'); }, 5000);
  });

  // Keep the voter dashboard's open/next election countdown precise while the page is open.
  var countdown = document.querySelector('[data-countdown-seconds]');
  if (countdown) {
    var secondsLeft = Math.max(0, parseInt(countdown.getAttribute('data-countdown-seconds'), 10) || 0);
    var updateCountdown = function () {
      countdown.textContent = String(Math.ceil(secondsLeft / 60));
      if (secondsLeft > 0) secondsLeft -= 1;
    };
    updateCountdown();
    window.setInterval(updateCountdown, 1000);
  }
})();
