/*
|--------------------------------------------------------------------------
| The switches of a chat
|--------------------------------------------------------------------------
| What the institution administrator can turn on or off per chat (Institution
| settings, Messages). `courseOnly` ones are drawn for course chats only.
|--------------------------------------------------------------------------
*/

export const SWITCHES = [
    { name: "lock", label: "Only the instructor can post", help: "Students can read but not write (announcements only).", courseOnly: true },
    { name: "reactions", label: "Reactions", help: "Members can react to messages." },
    { name: "replies", label: "Replies", help: "Members can reply with a quote." },
    { name: "forwarding", label: "Forwarding", help: "Members can forward messages out of this chat." },
    { name: "bump", label: "Bump", help: "Members can resend their own message as a reminder." },
    { name: "delete_everyone", label: "Delete for everyone", help: "Members can delete their own message for everyone for 15 minutes. Delete for me is always available." },
];
