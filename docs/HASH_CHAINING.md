# Encadenamiento SHA-256 e inmutabilidad (VeriFactu)

Esta guía explica cómo funciona el encadenamiento de facturas en este paquete y cómo comprobar que todo cumple con los requisitos de la AEAT (RD 1007/2023).

## Cómo funciona el flujo

Cada vez que se guarda una factura nueva, ocurren cuatro cosas en orden:

1. **Se busca la factura anterior** del mismo emisor (`issuer_tax_id`), incluyendo las eliminadas con *soft delete*. Si es la primera de la serie (`is_first_invoice = true`), la huella previa queda vacía.
2. **Se calcula la huella SHA-256** juntando los 8 datos que pide la AEAT (NIF emisor, número, fecha, tipo, cuota, total, huella anterior y fecha/hora ISO-8601).
3. **Se guarda la factura bloqueada.** Una vez que otra factura apunta a ella, o cuando ya tiene CSV de la AEAT, el modelo impide cambiar sus importes o datos fiscales.
4. **Se envía el XML a la AEAT** (si está en modo VERIFACTU) usando exactamente la misma huella y fecha que se guardaron en base de datos.

```text
Factura 1 (sin huella previa) -> Hash A
Factura 2 (usa Hash A)        -> Hash B
Factura 3 (usa Hash B)        -> Hash C
```

Si alguien cambiase un dato de la Factura 1, su hash dejaría de ser `Hash A` y el enlace con la Factura 2 quedaría roto.

## Qué se ha añadido o cambiado

* **`src/Helpers/HashHelper.php`**
  * `HashHelper::verifyInvoiceHash($hash, $data)`: recalcula la huella y comprueba si coincide.
  * `HashHelper::verifyChain($hashAnterior, $campoAnteriorActual)`: comprueba si dos facturas seguidas están bien enlazadas.
* **`src/Models/Invoice.php`**
  * Encadenamiento automático al guardar una factura nueva.
  * Bloqueo con `DomainException` si se intentan editar los datos fiscales de una factura ya encadenada o registrada en la AEAT. Para corregir un error hay que emitir una rectificativa o una subsanación.
  * `isChainValid()` para comprobar en cualquier momento si una factura y su enlace anterior son válidos.
* **`src/Services/AeatClient.php`**
  * Ahora usa `HashHelper::generateInvoiceHash()` para no duplicar el cálculo.
  * Respeta el `issued_at` del modelo para que la huella enviada a la AEAT sea exactamente la misma que la guardada en base de datos.
  * Rellena los datos de la factura anterior de forma automática si ya existen en el modelo.

## Ejemplo rápido de uso

```php
use Squareetlabs\VeriFactu\Models\Invoice;

// Comprobar que una factura y su enlace anterior son válidos
if ($factura->isChainValid()) {
    // La cadena está íntegra
}
```

## Pruebas realizadas

### 1. Casos oficiales de la AEAT (v0.1.2)

En `tests/Unit/InvoiceChainingTest.php` se comprueban los dos ejemplos oficiales publicados por la AEAT:

* **Caso 1** (`12345678/G33`, primera factura): debe generar `3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60`.
* **Caso 2** (`12345679/G34`, encadenada al Caso 1): debe generar `F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97`.

### 2. Ejecutar los tests en local

```bash
php vendor/phpunit/phpunit/phpunit tests/Unit
```

Última verificación en local: **62 tests ejecutados, 150 comprobaciones, 0 fallos** (1 test omitido por requerir certificado real).

### 3. Migraciones de base de datos

Se ha probado el ciclo completo de las 11 migraciones del paquete (`migrate:fresh`, `migrate:rollback` y `migrate`) sin errores de claves foráneas ni de tipos.
