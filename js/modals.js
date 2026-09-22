/* Create the basic modal structure */
function buildModal(modal_id) {
    if (document.getElementById('modal-overlay')) {
        return; // Modal already exists
    }

    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'modal-overlay';
    modalOverlay.id = modal_id;

    const modalBox = document.createElement('div');
    modalBox.className = 'modal-box';

    return { modalOverlay, modalBox };
}