# Kereskedésnevek kinyerése HTML-ből

Két verzió áll rendelkezésre: **Python** és **PHP**

## 📋 Előkészületek

### 1. Mentsd el a HTML fájlt
A VS Code-ban nyitott `Untitled-1` fájlt mentsd el, pl: `kereskedesek.html`

## 🐍 Python verzió (ajánlott)

### Telepítés
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE
pip3 install -r requirements.txt
```

### Használat
```bash
# Alapértelmezett használat (módosítsd a fájlneveket a scriptben!)
python3 extract_kereskedeslista_names.py

# VAGY parancssori argumentumokkal:
python3 extract_kereskedeslista_names.py kereskedesek.html kimeneti_lista.xlsx
```

## 🐘 PHP verzió (egyszerűbb)

### CSV verzió (nem igényel semmit)
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/MMS/MMS_BE

# Szerkeszd a fájlnevet a PHP scriptben:
# $htmlFilePath = 'kereskedesek.html';

php extract_kereskedeslista_names.php
```

Ez létrehoz egy **kereskedesek_lista.csv** fájlt, amit Excel is meg tud nyitni.

### XLSX verzió (igényel PhpSpreadsheet-et)
Ha már van PhpSpreadsheet a projektben (vendor/phpoffice/phpspreadsheet), akkor automatikusan XLSX-et is készít.

## 📤 Kimenet

Mindkét verzió **2 oszlopos táblázatot** készít:

| Kereskedés neve | URL |
|----------------|-----|
| 555 autó | /partner/555_auto-16834 |
| A 111 Autó | /partner/a_111_auto-9972 |
| A Ba-Ro Autókereskedés | /partner/a_ba-ro_autokereskedes-11402 |
| ... | ... |

## ✅ Gyors használat

1. Mentsd el a HTML fájlt ezen a néven: `kereskedesek.html`
2. Futtasd:
   ```bash
   python3 extract_kereskedeslista_names.py kereskedesek.html kereskedesek_lista.xlsx
   ```
   VAGY
   ```bash
   php extract_kereskedeslista_names.php
   ```
3. Nyisd meg a `kereskedesek_lista.xlsx` vagy `.csv` fájlt Excelben

## 🔧 Hibaelhárítás

### Python: ModuleNotFoundError
```bash
pip3 install beautifulsoup4 pandas openpyxl
```

### PHP: CSV helyett XLSX kell
Telepítsd a PhpSpreadsheet-et:
```bash
composer require phpoffice/phpspreadsheet
```
