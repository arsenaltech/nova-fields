<template>
  <r64-panel-item
    :field="field"
    :hide-label="hideLabelInDetail"
    :label-classes="panelLabelClasses"
    :field-classes="panelFieldClasses"
    :wrapper-classes="panelWrapperClasses"
  >
    <template #value>
      <r64-excerpt
        :content="field.value"
        :excerpt-classes="excerptClasses"
        :show-label="showContentLabel"
        :hide-label="hideContentLabel"
        :should-show="field.shouldShow"
      >
        <template #content>
          <TableWrapper :field="field" />
        </template>
      </r64-excerpt>
    </template>
  </r64-panel-item>
</template>

<script>
import TableWrapper from './TableWrapper.vue'
import R64Field from '../../mixins/R64Field'

export default {
  components: { TableWrapper },

  mixins: [R64Field],

  props: ['resource', 'resourceName', 'resourceId', 'field']
}
</script>
