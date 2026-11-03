// выбирает крестик или нолик для значка раздела игр при загрузке страницы
const gameIcon = document.querySelector('[data-nav="games"] .nav-icon');
gameIcon.textContent = Math.random() < 0.5 ? 'X' : 'O';
