
document.addEventListener('DOMContentLoaded', function() {
    var heartIcon = document.querySelector('.heart-icon');
    heartIcon.addEventListener('click', function() {
      this.classList.toggle('liked');
    });
  });
  
  