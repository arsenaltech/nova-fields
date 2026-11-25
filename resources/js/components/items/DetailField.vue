<template>
  <r64-panel-item

      :field="field"
      :hide-label="hideLabelInDetail"
      :label-classes="panelLabelClasses"
      :field-classes="panelFieldClasses"
      :wrapper-classes="panelWrapperClasses"
  >
    <template #value>
      <div>
        <component
            :item="item"
            :key="index"
            v-bind="$props"
            :is="field.detailItemComponent"
            v-for="(item, index) in field.value"
        />
      </div>
    </template>

  </r64-panel-item>
</template>

<script>
import R64Field from '../../mixins/R64Field'
export default {
  mixins: [R64Field],
  props: ['resource', 'resourceName', 'resourceId', 'field']
}
</script>
