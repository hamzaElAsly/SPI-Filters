import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

window.productsPage = (products, initialEditId = null, oldEdit = {}, oldAdd = {}) => ({
    products,
    initialEditId,
    oldEdit,
    oldAdd,
    modal: null,
    selected: null,
    editProduct: null,
    deleteProduct: null,
    imagePreview: null,
    addForm: { ref: '', description: '', prix_achat: '', prix_vente: '', categorie_id: '' },
    editForm: { ref: '', description: '', prix_achat: '', prix_vente: '', categorie_id: '' },
    init() {
        if (this.oldAdd.ref !== null && this.oldAdd.ref !== undefined) {
            this.addForm = { ...this.addForm, ...this.oldAdd };
        }
        if (this.initialEditId) {
            const product = this.products.find((item) => String(item.id) === String(this.initialEditId));
            if (product) this.openEdit(product);
        } else if (new URLSearchParams(window.location.search).get('openModal') === 'add') {
            this.openAdd();
        }
    },
    openAdd() {
        this.modal = 'add';
        this.addForm = { ref: '', description: '', prix_achat: '', prix_vente: '', categorie_id: '' };
        if (this.oldAdd.ref !== null && this.oldAdd.ref !== undefined) this.addForm = { ...this.addForm, ...this.oldAdd };
    },
    openDetails(product) { this.selected = product; this.modal = 'details'; },
    openEdit(product) {
        this.editProduct = product;
        this.editForm = { ref: product.ref, description: product.description, prix_achat: product.prix_achat, prix_vente: product.prix_vente, categorie_id: product.categorie_id || '' };
        if (this.oldEdit.ref !== null && this.oldEdit.ref !== undefined) this.editForm = { ...this.editForm, ...this.oldEdit };
        this.modal = 'edit';
    },
    openDelete(product) { this.deleteProduct = product; this.modal = 'delete'; },
    closeAll() { this.modal = null; this.selected = null; this.deleteProduct = null; },
    get deleteUrl() { return this.deleteProduct ? `/produits/${this.deleteProduct.id}` : '#'; },
    previewImage(event) {
        const [file] = event.target.files;
        if (file) this.imagePreview = URL.createObjectURL(file);
    },
    calculateOldPrice(value) {
        if (value === '' || value === null || value === undefined) return '—';
        const price = Number(value);
        if (!Number.isFinite(price) || price < 0) return '—';
        return this.formatPrice(price * 1.35);
    },
    formatPrice(value) { return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0)); },
});

window.usersPage = () => ({
    modal: null,
    deleteUser: null,
    init() {
        if (new URLSearchParams(window.location.search).get('openModal') === 'add') this.openAdd();
    },
    openAdd() { this.modal = 'add'; },
    openDelete(user) { this.deleteUser = user; this.modal = 'delete'; },
    closeAll() { this.modal = null; this.deleteUser = null; },
    get deleteUrl() { return this.deleteUser ? `/utilisateurs/${this.deleteUser.id}` : '#'; },
});

Alpine.start();
