# Plan: Tutorial Interactivo de Despacho SCZ en `hpos-ardxoz-woo-orders`

- **Plugin:** `hpos-ardxoz-woo-orders`
- **Ubicación:** `f:\VENTOVA\produccion\complementos\hpos-ardxoz-woo-orders\`

---

## 🎯 Propósito

Agregar un **Tutorial Interactivo Guía Paso a Paso** dentro de la pantalla de pedidos HPOS (`wc-orders`), diseñado para capacitar a los vendedores de **Santa Cruz (SCZ)**.

### Características:
- 🎓 **Botón Flotante / Cabecera:** "🎓 Guía de Despacho SCZ" en la parte superior de la tabla.
- 🎛️ **Navegación:** Botones **"Anterior"**, **"Siguiente"**, indicador **`Paso X de 6`** y botón de cierre.
- 🎯 **Resaltado Dinámico:** Enfoca y resalta visualmente con un halo brillante los elementos de la fila (Fila SCZ verde, badge Procesando, Botón Impresora 🖨️, Cantidades en rojo `x1`/`x2`, Badges `IBEX`/`CBS`).

---

## 📌 Pasos del Tutorial Interactivo:

1. **Paso 1: Identificación SCZ (Fondo Verde)** -> Explica el color de la fila y el badge `SCZ`.
2. **Paso 2: Estado Procesando** -> Explica que es un nuevo pedido que requiere atención.
3. **Paso 3: Impresión & Control de Cantidad** -> Enfoca el botón violeta 🖨️ y el texto de cantidad en rojo.
4. **Paso 4: Forma de Envío** -> Muestra la casilla de envío (`IBEX` o `CBS`).
5. **Paso 5: Regla IBEX** -> Explica que `IBEX` requiere elaboración de GUÍA + Nota de Entrega.
6. **Paso 6: Regla CBS** -> Explica que `CBS` es Delivery local y va únicamente con Nota de Entrega.
