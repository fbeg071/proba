# PROZONE Italic – uputstvo

Font PROZONE Italic je nacrtan na osnovu PROZONE loga. Sadrži velika slova A–Z, Č Ć Š Đ Ž, brojeve 0–9 i znakove . , : ; ! ? - + = / * ( ) ' " _ € % @ # & $ < > [ ]. Tipografski navodnici „ “ ” ‘ ’ i crtice – — (koje WordPress sam ubacuje) prikazuju se kao " ' i -.
Mala slova se automatski prikazuju kao velika.

| Font | Fajl | Za šta |
|---|---|---|
| **PROZONE Italic** | `PROZONE-Italic` | glavni font za naslove, banere, akcije, cijene |
| **PROZONE Logo Italic** | `PROZONELogo-Italic` | P, R, O, Z, N, E tačno kao u logu (za ispis riječi PROZONE) |

## 1. Računar (Windows / Mac)
Folder `racunar/`:
- **Windows:** desni klik na `.ttf` → **Install for all users**.
- **Mac:** dvoklik na `.ttf` → **Install Font**.

Nakon toga font je dostupan u Wordu, Photoshopu, Canvi (upload fonta), Illustratoru... pod imenom **PROZONE** (stil Italic).

## 2. prozone.ba (WordPress + Flatsome child tema)
Folder `web/`:
1. Fajlove iz `web/fonts/` (dva `.woff2`) postaviti u `wp-content/themes/flatsome-child/fonts/` (FTP ili File Manager na hostingu).
2. Sadržaj `web/prozone-fonts.css` dodati na kraj `wp-content/themes/flatsome-child/style.css`.
3. U Flatsome → Theme Options → Style → Typography font za naslove ostaviti kakav jeste. CSS iz koraka 2 ga preuzima za h1, h2, h3, naslove sekcija i proizvoda. Selektore po želji promijeniti.
4. Očistiti LiteSpeed keš (LiteSpeed Cache → Purge All).

Opciono, brže učitavanje: u `functions.php` child teme dodati:
```php
add_action('wp_head', function () {
    echo '<link rel="preload" href="' . get_stylesheet_directory_uri() . '/fonts/PROZONE-Italic.woff2" as="font" type="font/woff2" crossorigin>';
}, 1);
```

## Provjera
- Browser test (OTS): `.ttf` i `.woff2` prolaze.
- fontbakery: 78 provjera prošlo, 0 grešaka (vidi `validacija-fontbakery.md`).

© 2026 PROZONE (prozone.ba). Vlasništvo PROZONE.
