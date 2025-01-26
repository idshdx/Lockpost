# PGP Reply Symfony Application



### About 

Generate links that users can use to submit messages encrypted with your public key.


### Why

The app lets you create unique links that you can share with the person that desires to send you important information but doesn't know how to deal with PGP.

### How

Send your app link, and receive the confidential information encrypted in your inbox.

You generate a unique link with the app, then you share it with someone that needs to send you
secret information.
When that person visits your app link and submits the sensitive data, this is encrypted in
their browser with the PGP public key of the email you used on signup.
The encrypted message is sent to your email.
This means that the app is not able to see the original message, and no message is stored.

### The way it works

When you generate a link to share, you provide your email address, and the app checks your public key in common key 
servers providers.
When the shared link is opened, and the message is submitted, all the content is encrypted on the client side with 
the PGP public key.
The server then signs (experimental) the encrypted content.
Finally, the server forwards it to your e-mail address.


### Why is this safe

The app provides end-to-end encryption using OpenPGP.js library, which means that the submitted information will be
only visible to who created the link (the owner of the key), and the app will not save it.
No message is stored.

### What's PGP?
PGP is an encryption technology often used for signing, encrypting, and decrypting texts and files. This is what
the app uses to encrypt messages. You can read more about how to generate your PGP key on the help page.
