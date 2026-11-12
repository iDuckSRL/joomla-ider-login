/* IDer Login: il pulsante nel form di accesso apre la pagina di IDer. */
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-iderlogin-url]');
  if (button) {
    event.preventDefault();
    window.location.href = button.getAttribute('data-iderlogin-url');
  }
});
