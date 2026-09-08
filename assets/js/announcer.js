// Queuezy Audio Announcement System - Accurate Number Pronunciation

function formatTicketForSpeech(rawTicket) {
    if (!rawTicket) return '45';
    let str = String(rawTicket).trim().replace(/-/g, ' ');
    // Strip leading zeros from numeric parts so "045" -> "45" (spoken accurately as "forty-five")
    str = str.replace(/\b0+(\d+)/g, '$1');
    return str;
}

function announceTicket(ticketNumber, departmentName) {
    if (!('speechSynthesis' in window)) {
        console.warn('Speech synthesis is not supported in this browser.');
        return;
    }

    playChime();

    const formattedTicket = formatTicketForSpeech(ticketNumber);
    const text = departmentName 
        ? `Now calling ticket ${formattedTicket} for ${departmentName}`
        : `Now calling ticket ${formattedTicket}`;

    const announcement = new SpeechSynthesisUtterance(text);
    announcement.rate = 0.9;  // Slower and crisp
    announcement.pitch = 1.0;
    announcement.lang = 'en-US';

    window.speechSynthesis.cancel();
    setTimeout(() => {
        window.speechSynthesis.speak(announcement);
    }, 400);
}

function playChime() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioContext.createOscillator();
        const gain = audioContext.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(587.33, audioContext.currentTime); // D5
        osc.frequency.setValueAtTime(880, audioContext.currentTime + 0.15); // A5

        gain.gain.setValueAtTime(0.3, audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.00001, audioContext.currentTime + 0.5);

        osc.connect(gain);
        gain.connect(audioContext.destination);

        osc.start();
        osc.stop(audioContext.currentTime + 0.5);
    } catch (e) {
        console.log('Audio Context chime play warning:', e);
    }
}

window.QueuezyAnnouncer = {
    formatTicket: formatTicketForSpeech,
    speak: function (ticket, dept) {
        announceTicket(ticket, dept || 'counter');
    },
    announce: function (ticket, dept) {
        announceTicket(ticket, dept || 'counter');
    },
    chime: playChime
};