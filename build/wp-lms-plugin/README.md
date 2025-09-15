# WP LMS Plugin

Ein umfassendes Learning Management System (LMS) Plugin für WordPress mit Video-Lektionen, Code-Abschnitten, WASM-Integration und Stripe-Zahlungsabwicklung.

## Features

### Kurs-Management
- **Kurse**: Erstellen Sie Kurse mit Namen, Beschreibung und Preis in EUR
- **Kapitel**: Organisieren Sie Kurse in strukturierte Kapitel
- **Lektionen**: Fügen Sie Lektionen mit Videos, Dauer und Code-Abschnitten hinzu
- **Code-Abschnitte**: Integrieren Sie Sourcecode mit Syntax-Highlighting für Kotlin, Java, JavaScript und Python

### Zahlungsintegration
- **Stripe-Integration**: Sichere Zahlungsabwicklung über Stripe
- **Mehrwährungsunterstützung**: EUR, USD, GBP
- **Webhook-Unterstützung**: Automatische Zahlungsbestätigung
- **Kaufstatus-Tracking**: Verfolgen Sie den Status von Kurskäufen

### Lernoberfläche
- **Responsive Design**: Optimiert für Desktop und Mobile
- **Video-Player**: Integrierter HTML5-Video-Player mit Fortschrittsverfolgung
- **Interaktive Navigation**: Aufklappbare Kapitel und Lektionen
- **Code-Overlay**: Syntax-hervorgehobene Code-Anzeige mit Overlay-Panels
- **WASM-Integration**: Ausführung von Kotlin Compose WASM-Anwendungen

### WASM & SFTP
- **SFTP-Upload**: Sichere Übertragung von WASM-Dateien via SFTP
- **Datei-Management**: Upload, Anzeige und Löschung von WASM-Dateien
- **URL-Verwaltung**: Automatische URL-Generierung für hochgeladene Dateien

### Fortschrittsverfolgung
- **Benutzerfortschritt**: Detaillierte Verfolgung des Lernfortschritts
- **Video-Fortschritt**: Automatische Speicherung der Video-Position
- **Kursabschluss**: Berechnung des Kursabschlusses in Prozent
- **Lernstatistiken**: Umfassende Statistiken für Administratoren

## Installation

1. Laden Sie das Plugin in das `/wp-content/plugins/` Verzeichnis hoch
2. Aktivieren Sie das Plugin über das WordPress Admin-Panel
3. Konfigurieren Sie Stripe-Einstellungen unter "LMS Settings > Stripe Config"
4. Konfigurieren Sie SFTP-Einstellungen unter "LMS Settings > SFTP Config"

## Konfiguration

### Stripe-Konfiguration
1. Gehen Sie zu "LMS Settings > Stripe Config"
2. Geben Sie Ihren Stripe Secret Key ein (beginnt mit `sk_`)
3. Geben Sie Ihren Stripe Publishable Key ein (beginnt mit `pk_`)
4. Konfigurieren Sie den Webhook Secret für automatische Zahlungsbestätigungen
5. Webhook-URL: `https://ihre-domain.de/?wp_lms_stripe_webhook=1`

### SFTP-Konfiguration
1. Gehen Sie zu "LMS Settings > SFTP Config"
2. Geben Sie SFTP-Host, Port, Benutzername und Passwort ein
3. Konfigurieren Sie den Remote-Pfad für WASM-Dateien
4. Geben Sie die Basis-URL für den HTTP-Zugriff auf Dateien an

## Verwendung

### Kurs erstellen
1. Gehen Sie zu "Courses > Add New"
2. Geben Sie Titel, Beschreibung und Preis ein
3. Speichern Sie den Kurs

### Kapitel hinzufügen
1. Gehen Sie zu "Chapters > Add New"
2. Wählen Sie den zugehörigen Kurs aus
3. Geben Sie die Reihenfolge an
4. Speichern Sie das Kapitel

### Lektion erstellen
1. Gehen Sie zu "Lessons > Add New"
2. Wählen Sie das zugehörige Kapitel aus
3. Geben Sie Video-URL und Dauer ein
4. Fügen Sie Code-Abschnitte hinzu
5. Laden Sie WASM-Dateien für interaktive Code-Beispiele hoch

### Shortcodes
- `[lms_user_progress course_id="123"]` - Zeigt Benutzerfortschritt für einen Kurs
- `[lms_user_courses]` - Zeigt alle gekauften Kurse des Benutzers

## Technische Anforderungen

### Server-Anforderungen
- PHP 7.4 oder höher
- WordPress 5.0 oder höher
- MySQL 5.6 oder höher
- SSH2-Erweiterung für SFTP-Funktionalität

### Abhängigkeiten
- Stripe PHP SDK (wird automatisch geladen)
- jQuery (WordPress Standard)
- Prism.js für Syntax-Highlighting (enthalten)

## Datenbankstruktur

Das Plugin erstellt folgende Tabellen:

### wp_lms_course_purchases
Speichert Kurskäufe und Zahlungsinformationen.

### wp_lms_user_progress
Verfolgt den Lernfortschritt der Benutzer.

### wp_lms_wasm_files
Verwaltet hochgeladene WASM-Dateien.

## Sicherheit

- Alle AJAX-Anfragen sind mit WordPress-Nonces gesichert
- Benutzerberechtigungen werden überprüft
- Datei-Uploads werden validiert
- SQL-Injection-Schutz durch prepared statements
- XSS-Schutz durch Eingabevalidierung

## Anpassung

### Hooks und Filter
Das Plugin bietet verschiedene Hooks für Anpassungen:

```php
// Beispiel: Kurs-Preis modifizieren
add_filter('wp_lms_course_price', function($price, $course_id) {
    // Ihre Logik hier
    return $price;
}, 10, 2);
```

### CSS-Anpassungen
Überschreiben Sie die Plugin-Styles in Ihrem Theme:

```css
.wp-lms-learning-interface {
    /* Ihre Anpassungen */
}
```

## Fehlerbehebung

### Häufige Probleme

**SFTP-Verbindung schlägt fehl**
- Überprüfen Sie, ob die SSH2-Erweiterung installiert ist
- Verifizieren Sie die SFTP-Anmeldedaten
- Testen Sie die Verbindung über das Admin-Panel

**Stripe-Zahlungen funktionieren nicht**
- Überprüfen Sie die API-Schlüssel
- Stellen Sie sicher, dass Webhooks korrekt konfiguriert sind
- Prüfen Sie die Stripe-Logs im Dashboard

**Videos werden nicht abgespielt**
- Überprüfen Sie die Video-URL
- Stellen Sie sicher, dass das Video-Format unterstützt wird
- Prüfen Sie CORS-Einstellungen für externe Videos

## Support

Für Support und Fragen:
1. Überprüfen Sie die Dokumentation
2. Testen Sie die Verbindungen über das Admin-Panel
3. Prüfen Sie die Browser-Konsole auf JavaScript-Fehler
4. Aktivieren Sie WordPress-Debug-Modus für detaillierte Fehlermeldungen

## Changelog

### Version 1.0.28
- Initiale Veröffentlichung
- Vollständiges LMS mit Kursen, Kapiteln und Lektionen
- Stripe-Zahlungsintegration
- WASM und SFTP-Unterstützung
- Benutzerfortschrittsverfolgung
- Responsive Design
- Syntax-Highlighting für Code

## Lizenz

GPL v2 oder später

## Entwicklung

### Dateistruktur
```
wp-lms-plugin/
├── wp-lms-plugin.php          # Haupt-Plugin-Datei
├── includes/                  # PHP-Klassen
│   ├── class-admin.php
│   ├── class-database.php
│   ├── class-frontend.php
│   ├── class-post-types.php
│   ├── class-sftp-handler.php
│   ├── class-stripe-integration.php
│   └── class-user-progress.php
├── assets/                    # Frontend-Assets
│   ├── css/
│   │   ├── admin.css
│   │   ├── frontend.css
│   │   └── prism.css
│   └── js/
│       ├── admin.js
│       ├── frontend.js
│       └── prism.js
└── README.md
```

### Entwicklungsumgebung
1. WordPress-Entwicklungsumgebung einrichten
2. Plugin in `/wp-content/plugins/` platzieren
3. Stripe-Testschlüssel für Entwicklung verwenden
4. SFTP-Testserver für Datei-Uploads konfigurieren

## Mitwirkende

Entwickelt für ein umfassendes WordPress LMS mit modernen Web-Technologien und Best Practices.
