# Zugangsdaten per Konstante festlegen

Die Zugangsdaten für Google Drive lassen sich als Konstanten festlegen, z. B. in der `wp-config.php`. So landen sie nicht in der Datenbank und nicht in Datenbank-Backups, und jede Umgebung kann eigene Werte haben.

Erfordert _External Files in Media Library_ 6.0.0 oder neuer. Siehe auch die [Dokumentation des Hauptplugins](https://github.com/threadi/external-files-in-media-library/blob/master/docs/constants_de.md).

## So funktioniert es

* **Alle Pflichtfelder:** Die Konstanten werden nur genutzt, wenn für alle Pflichtfelder eine Konstante gesetzt ist (unten ohne „optional“). Fehlt eine, wird keine Konstante genutzt und die Einstellungsseite zeigt, welche fehlen.
* Der Speicherort der Zugangsdaten lässt sich dann nicht mehr ändern. In den Einstellungen erscheinen Hinweise statt der Eingabefelder.
* Google Drive nutzt OAuth. Als Konstanten lassen sich nur die API-Zugangsdaten eines eigenen OAuth-Clients festlegen. Der Speicherort der Zugangsdaten steht dann fest auf „Direkte Eingabe der API-Zugangsdaten“, die im OAuth-Client einzutragende Weiterleitungs-URL steht in den Einstellungen. Die Verbindung selbst wird weiterhin über „Jetzt verbinden“ hergestellt, die Tokens werden in der Datenbank gespeichert, weil Google sie regelmäßig erneuert. Die direkte Eingabe erfordert ein SSL-Zertifikat für eine öffentliche Domain.

Der Name jeder Konstante setzt sich zusammen aus `EFML_` + Name des Dienstes + `_` + Name des Feldes, alles in Großbuchstaben.

## Konstanten

```php
define( 'EFML_GOOGLE_DRIVE_CLIENT_ID', 'xy.apps.googleusercontent.com' );
define( 'EFML_GOOGLE_DRIVE_CLIENT_SECRET', 'dein-client-secret' );
```

## Nur eine Verbindung

Mehrere Verbindungen (z. B. `EFML_GOOGLE_DRIVE_CONNECTIONS`) sind bei Google Drive nicht möglich, weil sich als Konstanten nur die API-Zugangsdaten eines OAuth-Clients festlegen lassen.
