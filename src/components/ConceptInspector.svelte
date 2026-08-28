<script lang="ts">
  // SPDX-License-Identifier: AGPL-3.0-or-later

  import type { KnowledgeObjectDetail } from '../lib/api'
  import { inspectorStrings } from '../lib/strings'

  let { object }: { object: KnowledgeObjectDetail } = $props()
</script>

<dl class="inspector-fields">
  <dt>{inspectorStrings.description.label}</dt>
  <dd class:muted={object.description === null}>
    {object.description ?? inspectorStrings.description.empty}
  </dd>
</dl>
