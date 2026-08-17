=== CaffeOnline Feed Sync ===
Stable tag: 0.5.11

== Fixes / Neu in 0.5.11 ==

* Neuer manueller Vollabgleich: „Alle Quellen synchronisieren“ aktualisiert zuerst den CaffeOnline-Feed und startet danach den TopItaly-Sitemap-Abgleich mit Fortschrittsanzeige.

== Fixes / Neu in 0.5.10 ==

* Lager-Toleranz ist je Quelle und zugeordnetem Lager separat konfigurierbar. Ein Bestand kleiner oder gleich der Toleranz wird im Shop als 0 behandelt; CaffeOnline bleibt primär und TopItaly übernimmt nur, wenn der effektive CaffeOnline-Bestand 0 ist.

== Fixes / Neu in 0.5.9 ==

* Unveränderte Lieferantenbestände lösen keine WooCommerce-Produktspeicherung mehr aus und erscheinen nicht mehr als scheinbare Änderung im Batch-Bericht.

== Fixes / Neu in 0.5.8 ==

* TopItaly-Produkte mit Nespresso-Kompatibilität erhalten automatisch das globale Attribut `Kompatibilität → Nespresso®` sowie die bestehenden Kategorien `Nespresso®` und `Nespresso® kompatibel`.

== Fixes / Neu in 0.5.7 ==

* TopItaly-Produkte mit „Kaffeemaschine“ oder „Kaffeemaschinen“ im Produkttitel erhalten automatisch die bestehende Kategorie `Kaffeemaschinen`.

== Fixes / Neu in 0.5.6 ==

* Der CaffeOnline-/TopItaly-Batch zeigt beim Lagerbestand nun den tatsächlichen alten WooCommerce-Wert statt `undefined` an.

== Fixes / Neu in 0.5.5 ==

* Produkte aus Grundzutaten, Waschmittel und Hygiene sowie Spielzeug werden beim TopItaly-Scan nicht importiert; bereits rein von TopItaly angelegte Produkte dieser Gruppen werden mitsamt ihren Bildern entfernt.
* TopItaly-Bilder werden über Quell-URL und Dateihash dedupliziert. Bei reinen TopItaly-Produkten ersetzt ein Scan das bestehende Beitragsbild und die Galerie vollständig.

== Fixes / Neu in 0.5.4 ==

* TopItaly ordnet nur noch Kaffeebohnen, E.S.E. Pads oder Kaffeekapseln anhand des Produkttitels zu und bereinigt alte automatisch angehängte Marken-Kategorien beim nächsten Lauf.
* GTIN wird beim TopItaly-Import im Yoast-GTIN-Feld gespeichert; Packgrössen wie „100er Pack“ werden als Stück-Attribut übernommen.
* Lager-Kategorie kann je Quelle in den Einstellungen gewählt werden und folgt dem aktiven Lieferanten.

== Fixes / Neu in 0.5.3 ==
- **TopItaly Bilder:** Produktgalerien werden übernommen, als Beitragsbild/Galerie angehängt und bei Folge-Scans nicht doppelt heruntergeladen.
- **TopItaly Titel und Attribute:** Marken- und Produktname werden mit Bindestrichen formatiert; Packstückzahlen und Gramm-/Volumenangaben werden in die vorhandenen Attribute `Stück` und `Grösse` verschoben.

== Fixes / Neu in 0.5.2 ==
- **TopItaly Preise korrigiert:** Deutsche und schweizerische Preisformate wie `40,00` und `1.250,50` werden als Dezimalpreise statt als ganze Tausenderwerte importiert.
- **TopItaly Produktdaten ergänzt:** Bestand, GTIN/EAN und vorhandene Produktkategorien werden bei jedem Re-Run aktualisiert. Reine TopItaly-Produkte erhalten zusätzlich die Standard-GTIN-Felder.
- **Duplikatschutz erweitert:** Das Matching berücksichtigt nun auch bestehende GTIN-Felder, damit ein erneuter Sitemap-Scan vorhandene Produkte aktualisiert statt neue anzulegen.

== Fixes / Neu in 0.5.1 ==
- **TopItaly Scan-Fortschritt:** Der Sitemap-Abgleich zeigt nun einen Live-Fortschrittsbalken, erkannte Produkte und Abruffehler direkt im Plugin-Backend.

== Fixes / Neu in 0.5.0 ==
- **Mehrere Lieferanten:** CaffeOnline und TopItaly behalten Lager, Lieferanten-SKU und Einkaufspreis getrennt pro WooCommerce-Produkt. CaffeOnline ist immer primär; TopItaly wird nur bei CaffeOnline-Bestand 0 als Fallback aktiv. Reine TopItaly-Produkte werden als getrennte Entwürfe importiert.
- **TopItaly Sitemap-Scan:** Liest alle URLs aus der TopItaly-Sitemap im Hintergrund, lädt Produktseiten parallel und übernimmt EAN, TopItaly-SKU, UVP, verfügbaren Bestand und Kurzbeschreibung für bestehende, eindeutig zugeordnete Produkte.
- **Manuelle TopItaly-Einkaufspreise:** Produktdaten enthalten eigene Felder für TopItaly EAN, SKU und Einkaufspreis. Der Einkaufspreis wird nur übernommen, wenn TopItaly aktiv ist.

== Fixes / Neu in 0.4.17 ==
- **Produkt-Matching beim Sync korrigiert:** Der eigentliche Feed-Sync sucht Produkte jetzt zuerst über die Lieferanten-SKU (`CO-...`) und danach über GTIN/EAN. Zusätzlich werden `_sku`, `_vendor_sku`, `_bcl_original_sku` und `_global_unique_id` als Match-Metafelder berücksichtigt.

== Fixes / Neu in 0.4.16 ==
- **Feed-Sync-Spalte korrigiert:** Feed-Zeilen werden jetzt mit allen vorhandenen Schlüsseln indexiert (`GTIN`, `EAN`, `SKU`, `Key`). Produkte mit WooCommerce-SKU `CO-...` werden dadurch auch dann korrekt als im Feed gefunden markiert, wenn die Feed-Zeile zusätzlich eine GTIN/EAN enthält.

== Fixes / Neu in 0.4.15 ==
- **3h-Cron erweitert:** Supplier-Sales/Stock-/Einkaufspreis-Sync Hook läuft alle 3 Stunden (`cofs_hourly_supplier_stock_delta`).
- **Preisänderungslog erweitert:** Einkaufspreisänderungen aus dem manuellen Sync und dem 3h-Cron werden protokolliert.
- **Preisänderungen besser sichtbar:** Die Log-Tabelle zeigt Differenz und Prozentänderung; starke Sprünge ab 10% werden hervorgehoben.

== Fixes / Neu in 0.4.10 ==
- **Auto-Prepare beim manuellen Sync:** Klick auf **Sync starten** führt automatisch zuerst **Feed vorbereiten** aus.
- **Preisänderungs-Log:** Jede Änderung an `_purchase_price` wird in eigener DB-Tabelle gespeichert.
- **Neue Admin-Unterseite:** **Preisänderungen** zeigt die letzten Einkaufspreis-Änderungen inkl. Alt/Neu und Zeitpunkt.

== Fixes / Neu in 0.4.9 ==
- **Dry-Run entfernt:** Sync läuft nur noch als echter Apply-Sync.
- **Debug-Bereich entfernt:** Admin-UI und AJAX-Debug-Endpunkt entfernt.

== Fixes / Neu in 0.4.4 ==
- **Max. Zeilen greift sofort:** Cache-Key enthält jetzt `feed_url + max_rows`. Änderung von „Max. Zeilen“ erzeugt automatisch eine neue Cache-Datei.
- **„Neu laden erzwingen“** beim Button **Feed vorbereiten** → ignoriert TTL und baut den Cache direkt neu auf.
- Statuszeile zeigt nun `max_rows` an, damit du siehst, ob die Begrenzung aktiv ist.

== Hinweis ==
- Wenn sich nur die Batch-Größe ändert, muss der Cache nicht neu gebaut werden.
