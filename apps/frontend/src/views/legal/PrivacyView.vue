<script setup lang="ts">
import LegalShell from '@/components/legal/LegalShell.vue'
import { useCompany } from '@/composables/useCompany'

const { legalName, email, country } = useCompany()
</script>

<template>
  <LegalShell title="Política de Privacidad" updated="2026">
    <p>
      En Loop7 (operado por <strong>{{ legalName }}</strong>) nos tomamos en serio tu privacidad. Esta
      política explica qué datos tratamos, con qué fin y cuáles son tus derechos.
    </p>

    <h2>1. Datos que recopilamos</h2>
    <ul>
      <li><strong>Cuenta:</strong> nombre, correo electrónico y contraseña (almacenada con hash, nunca en claro).</li>
      <li><strong>Organización y marcas:</strong> datos de tu empresa, marcas, identidad y equipo.</li>
      <li><strong>Contenido:</strong> publicaciones, imágenes y archivos que creas o subes.</li>
      <li><strong>Conexiones a redes sociales:</strong> tokens de acceso que nos autorizas, guardados
        <strong>cifrados</strong>. Nunca almacenamos tus contraseñas de las redes.</li>
      <li><strong>Métricas e interacciones:</strong> estadísticas y mensajes/comentarios que las plataformas nos permiten leer.</li>
      <li><strong>Uso de IA:</strong> los textos que envías para generar contenido y el consumo de créditos.</li>
      <li><strong>Datos técnicos:</strong> registros de actividad e identificadores de solicitud, sin secretos.</li>
    </ul>

    <h2>2. Cómo usamos los datos</h2>
    <ul>
      <li>Prestar el servicio: programar y publicar contenido, analítica, inbox y automatizaciones.</li>
      <li>Generar contenido con IA a petición tuya.</li>
      <li>Facturación y control de límites de tu plan.</li>
      <li>Seguridad, prevención de abuso y soporte.</li>
    </ul>

    <h2>3. Terceros con los que compartimos datos</h2>
    <p>Solo lo necesario para operar, mediante proveedores que actúan por cuenta nuestra:</p>
    <ul>
      <li><strong>Plataformas sociales</strong> (p. ej. Meta/Facebook, Instagram) para publicar y leer interacciones.</li>
      <li><strong>Proveedores de IA</strong> (p. ej. OpenAI, Anthropic) para generar contenido.</li>
      <li><strong>Pasarelas de pago</strong> (p. ej. Stripe, Mercado Pago, Openpay) para procesar cobros. No almacenamos números de tarjeta.</li>
      <li><strong>Infraestructura</strong> de alojamiento y almacenamiento.</li>
    </ul>
    <p>No vendemos tus datos personales.</p>

    <h2>4. Seguridad</h2>
    <p>
      Ciframos los secretos sensibles (tokens de redes, claves de IA, credenciales de pasarela) en reposo, usamos
      HTTPS en tránsito, control de acceso por roles y aislamiento estricto entre organizaciones. Aun así, ningún
      sistema es 100% infalible.
    </p>

    <h2>5. Conservación</h2>
    <p>
      Conservamos tus datos mientras tu cuenta esté activa y según obligaciones legales. Puedes solicitar su borrado
      en cualquier momento (ver sección 7).
    </p>

    <h2>6. Tus derechos</h2>
    <p>
      Según tu jurisdicción, puedes acceder, rectificar, exportar u oponerte al tratamiento de tus datos, y solicitar
      su eliminación. Escríbenos a <a :href="`mailto:${email}`">{{ email }}</a>.
    </p>

    <h2>7. Borrado de datos</h2>
    <p>
      Puedes pedir la eliminación de tus datos desde <RouterLink to="/eliminar-datos">Borrado de datos</RouterLink>.
      Si conectaste una cuenta de Meta, también puedes iniciar el borrado desde la configuración de tu cuenta de
      Facebook; procesaremos la solicitud automáticamente.
    </p>

    <h2>8. Cambios</h2>
    <p>Podremos actualizar esta política; publicaremos la nueva versión aquí con su fecha.</p>

    <h2>9. Contacto</h2>
    <p><strong>{{ legalName }}</strong> — <a :href="`mailto:${email}`">{{ email }}</a> — <strong>{{ country }}</strong>.</p>
  </LegalShell>
</template>
