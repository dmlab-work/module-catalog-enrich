# DmLab_CatalogEnrich

> AI product attribute enrichment for Magento 2: fill empty filter attributes (material, sleeve, seats, Yes/No…) from the product descriptions you already have.

[![CI](https://github.com/dmlab-work/module-catalog-enrich/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-catalog-enrich/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-catalog-enrich/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-catalog-enrich) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-catalog-enrich)](https://packagist.org/packages/dmlab/module-catalog-enrich) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-catalog-enrich)](https://packagist.org/packages/dmlab/module-catalog-enrich) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-catalog-enrich/php)](https://packagist.org/packages/dmlab/module-catalog-enrich) [![License](https://img.shields.io/packagist/l/dmlab/module-catalog-enrich)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

![Suggestions waiting for review](.github/images/suggestions.png)

## The problem
A product only shows up under *Material: Cotton* or *Seats: 6* in layered navigation if that attribute is filled.
In most catalogs — supplier feeds, imports, years of manual entry — the answer is sitting in the description
("6-seater dining set", "long-sleeve hoodie") while the attribute is empty. Those products are invisible to every
shopper who filters, and nobody fills thousands of attributes by hand.

## What it does
Catalog Enrich reads each product's name and descriptions and picks the value **from the attribute's existing
options** — it never invents text or new options. Per attribute you choose whether values are only recorded,
wait for your approval, or are applied automatically. Every applied value can be reverted.

- **Precise** — select and Yes/No questions are asked twice, with the options in opposite order, and accepted only
  when both answers agree above your threshold. "Not stated" is always an option, so silence never becomes "No".
- **Safe** — fills empty values only (or appends missing options to a multiselect). Store-view values, configurable
  product variant attributes and values a human rejected are left alone.
- **Cheap** — unchanged products are never sent twice and identical requests are cached. Three attributes on
  148 Luma products cost $0.0065.
- **Transparent** — a review queue, a log with the outcome of every product × attribute, per-rule counts,
  last-run status and a monthly token budget.

On a real furniture store, tested on 149 products whose *Number of seats* was already known, one extra rule
("a dining set of N pieces is one table plus N−1 seats") let it answer 96 % of them with no wrong values.

## Install
```bash
composer require dmlab/module-catalog-enrich
bin/magento setup:upgrade
```
Magento 2.4.9+, PHP 8.3–8.5. Pulls in [`dmlab/module-typesafe-core`](https://github.com/dmlab-work/module-typesafe-core).
Answers come from [TypeSafe](https://typesafe.ai) (model Jev): you register at
[console.typesafe.ai](https://console.typesafe.ai) and pay TypeSafe directly ($0.042 per 1M input tokens).
Your API key stays encrypted in your Magento; we never see it.

## Quick start
1. **Stores → Configuration → DMLab → TypeSafe** — enable, paste the API key, click *Test Connection*.
2. **Catalog → Catalog Enrichment → Rules → Add New Rule** — pick an attribute. New rules start in *Shadow*
   (answers are recorded, nothing is suggested).
3. Run it: `bin/magento dmlab:catalog-enrich:run --limit=100`, or select products in the product grid →
   *Actions → Enrich attributes*, or enable the nightly cron.
4. `bin/magento dmlab:catalog-enrich:calibrate --attribute=<code>` measures precision on products that already
   have a value and suggests a threshold.
5. Switch the rule to *Review* and approve in **Catalog Enrichment → Suggestions** — or to *Auto*.

| Rules: status and outcomes per attribute | Rule: threshold and extra rules |
|---|---|
| ![Rules](.github/images/rules.png) | ![Rule form](.github/images/rule-form.png) |

**Extra Rules** are the main accuracy lever: one plain sentence per line that tells the model how your catalog
names things ("A tank top is sleeveless.").

## Reference
| | |
|---|---|
| Attributes | select (≤ 254 options), multiselect, Yes/No |
| Rules | mode `off` · `shadow` · `review` · `auto`; threshold 0.5–1 (default 0.9); fill `empty_only` or `add_missing` (multiselect); *Treat No as Empty* (Yes/No) |
| Admin | Catalog → Catalog Enrichment: Rules, Suggestions (approve / reject / revert), Attempts (*Ask again on next run*) |
| CLI | `dmlab:catalog-enrich:run [--attribute] [--sku] [--limit] [--force] [--dry-run]` · `calibrate --attribute [--sample]` · `report` |
| Settings | Stores → Configuration → DMLab → Catalog Enrichment: extra text attributes, products not visible individually, nightly cron and products per run |
| Background | queue consumers `dmlab.catalog_enrich.products` and `dmlab.catalog_enrich.suggestions` (started by `cron_consumers_runner`); nightly cron (off by default); hourly re-queue of runs that stopped |
| ACL | `DmLab_CatalogEnrich::rules`, `::suggestions`, `::suggestions_apply`, `::attempts`, `::run`, `::config` |

A product × attribute is sent again only when its text, the options, the rule text or the model change — or when
you ask (`--force`, *Ask again*). Data TypeSafe refuses is not retried until it changes; other errors back off from
1 hour up to 7 days.

## Data and privacy
Sent to TypeSafe (US-hosted API): product name, short description and description (HTML stripped, up to 6,000
characters), the extra text attributes you chose, the attribute's label and option labels, and your rule text.
No customer or order data. Zero data retention is available on TypeSafe's enterprise plan.

## License
[OSL-3.0](LICENSE)
