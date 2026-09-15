declare const process: { env: Record<string, string | undefined> }

const members = [{
  name: 'Anthony Fu',
  username: 'antfu',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/antfu' }
}, {
  name: 'Baptiste Leproux',
  username: 'larbish',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/larbish' }
}, {
  name: 'Benjamin Canac',
  username: 'benjamincanac',
  role: 'owner',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/benjamincanac' }
}, {
  name: 'Céline Dumerc',
  username: 'celinedumerc',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/celinedumerc' }
}, {
  name: 'Daniel Roe',
  username: 'danielroe',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/danielroe' }
}, {
  name: 'Farnabaz',
  username: 'farnabaz',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/farnabaz' }
}, {
  name: 'Ferdinand Coumau',
  username: 'FerdinandCoumau',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/FerdinandCoumau' }
}, {
  name: 'Hugo Richard',
  username: 'hugorcd',
  role: 'owner',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/hugorcd' }
}, {
  name: 'Pooya Parsa',
  username: 'pi0',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/pi0' }
}, {
  name: 'Sarah Moriceau',
  username: 'SarahM19',
  role: 'member',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/SarahM19' }
}, {
  name: 'Sébastien Chopin',
  username: 'Atinux',
  role: 'owner',
  avatar: { src: 'https://ipx.nuxt.com/f_auto,s_192x192/gh_avatar/atinux' }
}]

export default eventHandler(async () => {
  if (process.env.NODE_ENV === 'production') {
    throw createError({ statusCode: 410, statusMessage: 'Gone', message: 'Mock do template desabilitado em produção.' })
  }
  return members
})
