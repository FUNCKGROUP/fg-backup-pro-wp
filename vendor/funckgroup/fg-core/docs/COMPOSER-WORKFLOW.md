# Empfohlener Composer-Workflow

## Ziel

Composer verwaltet FG Core nur während der Entwicklung. Das an WordPress ausgelieferte Plugin enthält danach eine feste Kopie unter `includes/fg-core/`. Auf der Kundeninstallation wird Composer nicht benötigt.

## Zentrales privates Repository

Das Repository `funckgroup/fg-core` hat im Root diese Paketkennung:

```json
{
  "name": "funckgroup/fg-core",
  "description": "Embedded runtime for FUNCKGROUP WordPress plugins",
  "type": "library",
  "license": "proprietary",
  "require": {
    "php": ">=7.4"
  }
}
```

Der auszuliefernde Core liegt im Repository unter:

```text
includes/fg-core/
```

Jede veröffentlichte Core-Version sollte als Git-Tag angelegt werden, zum Beispiel `1.1.3`.

## Plugin-Repository

Im jeweiligen Plugin wird FG Core als private Entwicklungsabhängigkeit ergänzt:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:funckgroup/fg-core.git"
    }
  ],
  "require-dev": {
    "funckgroup/fg-core": "^1.1"
  },
  "scripts": {
    "fg-core:sync": "@php tools/sync-fg-core.php",
    "post-install-cmd": [
      "@fg-core:sync"
    ],
    "post-update-cmd": [
      "@fg-core:sync"
    ]
  }
}
```

Das Plugin benötigt außerdem eine eigene Kopie von `tools/sync-fg-core.php`. Composer führt Skripte aus Abhängigkeiten nicht automatisch aus; deshalb muss der Sync-Befehl im Root-`composer.json` jedes Plugins stehen.

## Erstinstallation

```bash
composer require --dev funckgroup/fg-core:^1.1
composer fg-core:sync
```

Falls das private Repository noch nicht im `composer.json` eingetragen ist, zuerst den `repositories`-Block ergänzen.

## Spätere Updates

```bash
composer update funckgroup/fg-core
```

Durch `post-update-cmd` wird der neue Core anschließend automatisch aus

```text
vendor/funckgroup/fg-core/includes/fg-core/
```

nach

```text
includes/fg-core/
```

kopiert. Der eingebettete Ordner und die aktualisierte `composer.lock` werden danach mit dem Plugin-Repository committed.

## Lokaler Checkout als Alternative

```bash
FG_CORE_SOURCE=/pfad/zu/fg-core/includes/fg-core composer fg-core:sync
```

## Warum nicht direkt aus `vendor/` laden?

- Das Plugin-ZIP bleibt eigenständig.
- Die Kundeninstallation braucht weder Composer noch GitHub-Zugriff.
- `vendor/` enthält möglicherweise weitere Entwicklungsabhängigkeiten.
- Alle Plugins verwenden zur Laufzeit dieselbe feste Zielstruktur `includes/fg-core/`.
