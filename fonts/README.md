# Шрифт для PDF

`DejaVuSans.ttf` — TrueType с кириллицей, используется генератором PDF (`src/pdf.php`).
Лежит в репозитории, поэтому сайт запускается на любом ПК без установки шрифтов в систему.

Источник: DejaVu Fonts (https://github.com/dejavu-fonts/dejavu-fonts), лицензия — в файле LICENSE.
Заменить можно любым TrueType (.ttf) с кириллицей; путь задаётся в config.php → pdf.font.
OpenType/CFF (.otf) не поддерживается.