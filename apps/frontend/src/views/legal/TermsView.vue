<script setup lang="ts">
import LegalShell from '@/components/legal/LegalShell.vue'
import { useCompany } from '@/composables/useCompany'

const { legalName, email, country } = useCompany()
</script>

<template>
  <LegalShell title="Términos de Servicio" updated="2026">
    <p>
      Estos Términos regulan el uso de Loop7 (el "Servicio"), operado por <strong>{{ legalName }}</strong>. Al
      crear una cuenta o usar el Servicio, aceptas estos Términos.
    </p>

    <h2>1. El servicio</h2>
    <p>
      Loop7 es una plataforma para gestionar marcas y redes sociales: crear y programar contenido (con ayuda de IA),
      aprobar, publicar, medir resultados, responder mensajes y automatizar tareas.
    </p>

    <h2>2. Cuentas</h2>
    <ul>
      <li>Debes proporcionar información veraz y mantener segura tu contraseña.</li>
      <li>Eres responsable de la actividad de tu organización y de los permisos que otorgas a tu equipo.</li>
      <li>Debes tener autorización para conectar las cuentas de redes sociales que vincules.</li>
    </ul>

    <h2>3. Uso aceptable</h2>
    <ul>
      <li>No usar el Servicio para spam, contenido ilegal, engañoso o que infrinja derechos de terceros.</li>
      <li>Respetar las políticas de cada red social y proveedor conectado.</li>
      <li>No intentar vulnerar la seguridad ni el aislamiento entre organizaciones.</li>
    </ul>

    <h2>4. Tu contenido</h2>
    <p>
      El contenido que creas o subes es tuyo. Nos concedes una licencia limitada para almacenarlo y procesarlo con el
      único fin de prestarte el Servicio (por ejemplo, publicarlo en las redes que autorices).
    </p>

    <h2>5. Planes y pagos</h2>
    <p>
      Algunos planes son de pago y se facturan de forma recurrente a través de la pasarela correspondiente. Los
      límites de uso dependen de tu plan. Salvo obligación legal, los importes ya cobrados no son reembolsables.
    </p>

    <h2>6. Integraciones de terceros</h2>
    <p>
      El Servicio se conecta con plataformas de terceros (redes sociales, IA, pagos). No somos responsables de la
      disponibilidad ni de los cambios en sus APIs o políticas.
    </p>

    <h2>7. Disponibilidad</h2>
    <p>Nos esforzamos por mantener el Servicio disponible, pero se ofrece "tal cual", sin garantías de continuidad.</p>

    <h2>8. Limitación de responsabilidad</h2>
    <p>
      En la máxima medida permitida por la ley, <strong>{{ legalName }}</strong> no será responsable de daños
      indirectos o pérdida de datos derivados del uso del Servicio.
    </p>

    <h2>9. Terminación</h2>
    <p>
      Puedes cancelar cuando quieras. Podemos suspender cuentas que incumplan estos Términos. Tras la terminación,
      tus datos se tratan según la Política de Privacidad.
    </p>

    <h2>10. Ley aplicable</h2>
    <p>Estos Términos se rigen por las leyes de <strong>{{ country }}</strong>.</p>

    <h2>11. Contacto</h2>
    <p><strong>{{ legalName }}</strong> — <a :href="`mailto:${email}`">{{ email }}</a>.</p>
  </LegalShell>
</template>
