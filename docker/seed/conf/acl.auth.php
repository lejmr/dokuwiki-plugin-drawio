# Dev ACL: everybody may read and upload media, so the draw.io editor opens
# without logging in. Log in as admin/admin for the admin interface.
*   @ALL    8
# A namespace only admin can read. secret:hidden.png lives there so the
# walkthrough can show that a diagram you may not read is neither searchable
# nor exported - see whatsnew sections 4 and 10.
secret:*    @ALL    0
