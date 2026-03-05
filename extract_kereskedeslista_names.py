#!/usr/bin/env python3
"""
Kereskedésnevek kinyerése HTML-ből és mentés Excel fájlba
"""

from bs4 import BeautifulSoup
import pandas as pd
import sys

def extract_kereskedeslista_names(html_file_path, output_xlsx_path):
    """
    Kigyűjti a kereskedeslista_nev class-ú elemek szövegét és xlsx-be menti
    
    Args:
        html_file_path: Az input HTML fájl elérési útja
        output_xlsx_path: A kimeneti XLSX fájl elérési útja
    """
    print(f"HTML fájl beolvasása: {html_file_path}")
    
    # HTML beolvasása
    with open(html_file_path, 'r', encoding='utf-8') as f:
        html_content = f.read()
    
    # BeautifulSoup parser
    soup = BeautifulSoup(html_content, 'html.parser')
    
    # Kereskedés nevek kigyűjtése
    names = []
    elements = soup.find_all('a', class_='kereskedeslista_nev')
    
    for element in elements:
        name = element.get_text(strip=True)
        href = element.get('href', '')
        names.append({
            'Kereskedés neve': name,
            'URL': href
        })
    
    print(f"Talált kereskedések száma: {len(names)}")
    
    # DataFrame létrehozása
    df = pd.DataFrame(names)
    
    # Excel fájlba mentés
    df.to_excel(output_xlsx_path, index=False, sheet_name='Kereskedések')
    
    print(f"✅ Sikeres mentés: {output_xlsx_path}")
    print(f"\nElső 5 kereskedés:")
    print(df.head())
    
    return df

if __name__ == "__main__":
    # Fájl útvonalak
    html_file = "untitled_Untitled-1.html"  # Módosítsd a tényleges fájlnévre!
    output_file = "kereskedesek_lista.xlsx"
    
    # Ha parancssori argumentumok vannak
    if len(sys.argv) > 1:
        html_file = sys.argv[1]
    if len(sys.argv) > 2:
        output_file = sys.argv[2]
    
    try:
        extract_kereskedeslista_names(html_file, output_file)
    except FileNotFoundError:
        print(f"❌ Hiba: A '{html_file}' fájl nem található!")
        print(f"\nHasználat:")
        print(f"  python3 extract_kereskedeslista_names.py <html_fájl> [xlsx_fájl]")
        print(f"\nPélda:")
        print(f"  python3 extract_kereskedeslista_names.py input.html output.xlsx")
        sys.exit(1)
    except Exception as e:
        print(f"❌ Hiba történt: {e}")
        sys.exit(1)
