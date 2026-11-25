import _ from 'lodash';

export default {
    mounted() {
        this.toggleEnabled = this.field.toggle && _.toArray(this.field.toggle).length;

        if (this.toggleEnabled) {
            this.mapFields();
            this.calculateFieldVisibility();
        }
    },
    computed: {

    },
    data() {
        return {
            toggleEnabled: false,
            toggleFields: {},
        }
    },
    methods: {
        findFieldsWhichCanBeToggled() {
            const fieldsToToggle = _.uniq(
                _.flatten(
                    _.toArray(this.field.toggle)
                )
            );

            const walk = (vnode) => {
                if (!vnode) return;
                if (vnode.component) {
                    const component = vnode.component.proxy;
                    if (component && component.field && fieldsToToggle.indexOf(component.field.attribute) !== -1) {
                        this.toggleFields[component.field.attribute] = component;
                    }
                    walk(vnode.component.subTree);
                }
                if (Array.isArray(vnode.children)) {
                    for (const child of vnode.children) {
                        if (child && typeof child === 'object') walk(child);
                    }
                }
            };
            if (this.$ && this.$.parent && this.$.parent.subTree) {
                walk(this.$.parent.subTree);
            }
        },
        generateFieldMap() {

        },
        mapFields() {
            this.findFieldsWhichCanBeToggled();
            this.generateFieldMap();
        },
        resetVisibility() {
            return _.each(this.toggleFields, field => {
                field.$el.classList.remove('mlbz-hidden');
            });
        },
        calculateFieldVisibility() {
            this.resetVisibility();

            const fields = this.field.toggle[this.rawValue];

            (fields || []).forEach(field => {
                if (this.toggleFields[field]) {
                    this.toggleFields[field].$el.classList.add('mlbz-hidden')
                }
            })
        }
    },
    watch: {
        value() {
            return this.toggleEnabled && this.calculateFieldVisibility();
        }
    }
}
