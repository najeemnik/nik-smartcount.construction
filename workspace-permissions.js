/* UI mirrors the current server decision; API checks remain authoritative. */
window.NSC_CAN = function (module, action = 'view') {
    const user = window.NSC_ACCESS_USER;
    if (!user) return false;
    if (user.isMasterSuperAdmin) return true;
    const rights = user.effectivePermissions?.[module];
    if (!rights) return false;
    return rights.view === true && rights[action === 'del' ? 'delete' : action] === true;
};
window.NSC_FILTER_ACTION = function (key) {
    const modules = {newProject:'projects',project:'projects',addProject:'projects',expense:'expenses',addExpense:'expenses',payment:'payments',addPayment:'payments',steel:'steel',addSteel:'steel',concrete:'concrete',addConcrete:'concrete',apartment:'apartments',addApartment:'apartments',contractor:'contractors',addContractor:'contractors',income:'payments',addIncome:'payments',material:'suppliers',addMaterial:'suppliers',labor:'labor',addLabor:'labor',transfer:'projects',addTransfer:'projects',pettyCash:'expenses',addPettyCash:'expenses',asset:'assets',addAsset:'assets'};
    return modules[key] ? window.NSC_CAN(modules[key], 'create') : false;
};
