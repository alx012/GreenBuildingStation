document.addEventListener("DOMContentLoaded", function () {
    var dropdownElement = document.querySelector('.dropdown-toggle');
    dropdownElement.addEventListener('mouseover', function () {
        var dropdown = new bootstrap.Dropdown(dropdownElement);
        dropdown.show();
    });
});
