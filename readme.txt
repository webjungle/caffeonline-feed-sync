=== CaffeOnline Feed Sync ===
Stable tag: 0.4.17

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
