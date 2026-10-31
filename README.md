# 📍 Tableau de Bord Météo — Luxeuil-les-Bains

Application PHP/MySQL monopage affichant la météo en temps réel, l'historique des mesures, les horaires du soleil et le compte à rebours des fêtes françaises. Thème sombre ou clair au choix, mémorisé dans le navigateur.

---

## ✨ Fonctionnalités

| Fonctionnalité | Détail |
|---|---|
| 🌓 Mode sombre / clair | Bascule en un clic, mémorisée dans `localStorage`, respecte la préférence système |
| 📅 Date & horloge live | Date en français + horloge JavaScript qui tourne en temps réel |
| 🌡️ Météo actuelle | Température, ressenti, humidité, vent, précipitations, conditions |
| 🌅 Soleil | Lever, coucher et barre de progression de la journée |
| 📅 Prévisions 5 jours | Température min/max, icône météo, précipitations |
| 🗓️ Compte à rebours | Jours restants avant le 14 Juillet, 15 Août, Noël, Réveillon |
| 🗄️ Historique MySQL | Sauvegarde automatique toutes les 30 min, tableau des 20 dernières mesures |
| 📊 Statistiques 30 jours | Temp. min/max/moy, humidité, vent max, précipitations totales |
| 📈 Graphiques interactifs | Courbes température, humidité, précipitations (Chart.js) |
| ⬇️ Export CSV | Téléchargement de tout l'historique, compatible Excel (BOM UTF-8) |

---

## 🛠️ Prérequis

- **PHP** 8.0 ou supérieur (extension `pdo_mysql` activée)
- **MariaDB** 10.3+ ou **MySQL** 5.7+
- **Apache** ou **Nginx** avec `mod_php` ou `php-fpm`
- Accès Internet depuis le serveur (appel à l'API Open-Meteo)
- Aucune clé API requise

---

## 🚀 Installation

### 1. Créer la base de données MySQL

Se connecter à MariaDB/MySQL en tant que `root` et exécuter :

```sql
CREATE DATABASE IF NOT EXISTS meteo_lxb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'meteo_user'@'localhost' IDENTIFIED BY 'MotDePasseSecurise!';
GRANT ALL PRIVILEGES ON meteo_lxb.* TO 'meteo_user'@'localhost';
FLUSH PRIVILEGES;
```

> La table `historique_meteo` est **créée automatiquement** au premier chargement de la page.

### 2. Déposer le fichier sur le serveur

```bash
cp tableau_de_bord.php /var/www/html/meteo/
```

### 3. Configurer les constantes

Ouvrir `tableau_de_bord.php` et ajuster le bloc de configuration en haut du fichier :

```php
// Localisation
define('LAT',   47.8167);          // Latitude
define('LON',    6.3833);          // Longitude
define('VILLE', 'Luxeuil-les-Bains');
define('TZ',    'Europe/Paris');   // Fuseau horaire PHP

// Base de données
define('DB_HOST', 'localhost');
define('DB_NAME', 'meteo_lxb');
define('DB_USER', 'meteo_user');
define('DB_PASS', 'MotDePasseSecurise!');  // ← à changer
define('DB_PORT', 3306);

// Comportement
define('SAVE_INTERVAL_MIN', 30);   // Intervalle de sauvegarde (minutes)
define('HISTORY_ROWS',      20);   // Lignes affichées dans le tableau
```

### 4. Vérifier les permissions Apache/Nginx

Le répertoire temporaire PHP doit être accessible en écriture (cache météo) :

```bash
# Vérifier que le cache peut être écrit
ls -la /tmp/lxb_cache/   # créé automatiquement
```

---

## 🗄️ Structure de la base de données

### Table `historique_meteo`

```sql
CREATE TABLE historique_meteo (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    enregistre_le   DATETIME         NOT NULL,
    ville           VARCHAR(80)      NOT NULL,
    latitude        DECIMAL(8,4)     NOT NULL,
    longitude       DECIMAL(8,4)     NOT NULL,
    temperature     DECIMAL(5,2)     DEFAULT NULL,  -- °C
    ressenti        DECIMAL(5,2)     DEFAULT NULL,  -- °C ressenti
    humidite        TINYINT UNSIGNED DEFAULT NULL,  -- % HR
    vent_kmh        DECIMAL(6,2)     DEFAULT NULL,  -- km/h
    precipitation   DECIMAL(6,2)     DEFAULT NULL,  -- mm
    code_wmo        SMALLINT UNSIGNED DEFAULT NULL, -- Code météo WMO
    condition_texte VARCHAR(80)      DEFAULT NULL,
    lever_soleil    TIME             DEFAULT NULL,
    coucher_soleil  TIME             DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_date  (enregistre_le),
    INDEX idx_ville (ville)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 📡 Source des données météo

Les données proviennent de l'API **[Open-Meteo](https://open-meteo.com)** :

- **Gratuite**, sans inscription, sans clé API
- Mise à jour toutes les heures environ
- Les résultats sont mis en **cache local 10 minutes** (`/tmp/lxb_cache/`) pour éviter de surcharger l'API

### Codes météo WMO utilisés

| Code | Condition |
|------|-----------|
| 0 | Ciel dégagé ☀️ |
| 1–3 | Peu nuageux à couvert 🌤️ ⛅ ☁️ |
| 45, 48 | Brouillard 🌫️ |
| 51–55 | Bruine 🌦️ |
| 61–65 | Pluie 🌧️ |
| 71–75 | Neige 🌨️ ❄️ |
| 80–82 | Averses 🌦️ ⛈️ |
| 95–99 | Orage ⛈️ 🌩️ |

---

## ⬇️ Export CSV

Cliquer sur le bouton **⬇️ Exporter CSV** dans la section historique, ou accéder directement à :

```
http://votre-serveur/meteo/tableau_de_bord.php?export=csv
```

Le fichier généré est encodé en **UTF-8 avec BOM** pour une ouverture correcte dans Microsoft Excel. Les colonnes sont séparées par des **points-virgules** (standard français).

---

## 📁 Structure des fichiers

```
meteo/
├── tableau_de_bord.php   # Application complète (fichier unique)
└── README.md             # Ce fichier
```

Le cache météo est stocké automatiquement dans le répertoire temporaire du système :

```
/tmp/lxb_cache/
└── *.json                # Fichiers cache (10 min, nommés par hash MD5 de l'URL)
```

---

## 🔒 Sécurité

- Toutes les requêtes SQL utilisent des **requêtes préparées PDO** (protection injection SQL)
- Le mot de passe MySQL ne doit pas être celui par défaut — le changer dans les constantes
- Les erreurs PDO sont loguées silencieusement (`error_log`) sans exposer de détails à l'utilisateur
- Pour une mise en production, placer le fichier de configuration dans un répertoire hors de la racine web

---

## 🐛 Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| Badge rouge « MySQL hors ligne » | Mauvaises constantes DB | Vérifier `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` |
| Météo affiche `—` partout | Pas d'accès Internet ou API indisponible | Vérifier la connectivité du serveur vers `api.open-meteo.com` |
| Erreur `1064` MariaDB | Version ancienne de MariaDB | S'assurer d'utiliser MariaDB 10.3+ |
| Cache trop ancien | Dossier `/tmp/lxb_cache/` non accessible | Vérifier les permissions du répertoire temporaire PHP |
| Export CSV vide | Table vide ou DB déconnectée | Attendre une première sauvegarde (30 min) |

---

## 📄 Licence

Projet personnel — libre d'utilisation et de modification.

---

*Données météo fournies par [Open-Meteo](https://open-meteo.com) · Graphiques par [Chart.js](https://www.chartjs.org) · Polices [DM Sans & DM Serif Display](https://fonts.google.com)*