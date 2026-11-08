/* UI mirrors the current server decision; API checks remain authoritative. */
window.NSC_CAN = function (module, action = 'view') {
    const user = window.NSC_ACCESS_USER;
    if (!user) return false;
    if (user.isMasterSuperAdmin) return true;
    const rights = user.effectivePermissions?.[module];
    if (!rights) return false;
    const verb = action === 'del' ? 'delete' : action;
    if (rights.view !== true || rights[verb] !== true) return false;
    if (user.role === 'admin') return true;
    const project = window.NSC_ACTIVE_PROJECT_ID;
    if (!project) return true;
    const assigned = user.assignedProjectIds || [];
    if (assigned.length && !assigned.includes('*') && !assigned.includes(project)) return false;
    const grants = user.projectGrants || [];
    if (!grants.length) return true;
    return grants.some(grant => {
        if (grant.projectId !== '*' && grant.projectId !== project) return false;
        const section = grant.sections?.[module];
        return section?.view === true && (verb === 'view' || section[verb] === true || (verb === 'delete' && section.del === true));
    });
};
window.NSC_FILTER_ACTION = function (key) {
    const modules = {newProject:'projects',project:'projects',addProject:'projects',expense:'expenses',addExpense:'expenses',payment:'payments',addPayment:'payments',steel:'steel',addSteel:'steel',concrete:'concrete',addConcrete:'concrete',apartment:'apartments',addApartment:'apartments',contractor:'contractors',addContractor:'contractors',income:'payments',addIncome:'payments',material:'suppliers',addMaterial:'suppliers',labor:'labor',addLabor:'labor',transfer:'projects',addTransfer:'projects',pettyCash:'expenses',addPettyCash:'expenses',asset:'assets',addAsset:'assets'};
    return modules[key] ? window.NSC_CAN(modules[key], 'create') : false;
};
